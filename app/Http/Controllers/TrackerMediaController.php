<?php

namespace App\Http\Controllers;

use App\Support\TrackerHistory;
use App\Support\TrackerMediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TrackerMediaController extends Controller
{
    public function saveProjectEdit(Request $request): JsonResponse
    {
        $user = (array) $request->session()->get('tracker_user');
        if (empty($user['canEdit'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to edit projects.'], 403);
        }
        $request->validate(['projectId'=>['required','integer','min:1'],'record'=>['required','string'],'deleteIds'=>['sometimes','nullable','string']]);
        $projectId = (int) $request->input('projectId');
        $incoming = json_decode((string) $request->input('record'), true);
        $deleteIds = json_decode((string) $request->input('deleteIds', '[]'), true);
        if (! is_array($deleteIds) || ! array_is_list($deleteIds) || count($deleteIds) > 1000
            || array_filter($deleteIds, fn ($id) => ! is_string($id) || $id === '' || strlen($id) > 128)) {
            return response()->json(['ok'=>false,'message'=>'Attachment IDs must be a list of non-empty strings.'],422);
        }
        if (is_array($incoming)) \App\Support\TrackerProjectInput::validate($incoming);
        if ($projectId < 1 || ! is_array($incoming) || ! is_array($deleteIds) || trim((string) ($incoming['title'] ?? '')) === '') {
            return response()->json(['ok' => false, 'message' => 'Invalid project edit.'], 422);
        }
        $files = $request->file('files', []);
        $files = is_array($files) ? $files : ($files ? [$files] : []);
        if (count($files) > 20) {
            return response()->json(['ok' => false, 'message' => 'Upload up to 20 attachments at a time.'], 422);
        }
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
        foreach ($files as $file) {
            if (!$file instanceof \Illuminate\Http\UploadedFile || ! $file->isValid() || $file->getSize() < 1 || $file->getSize() > 25 * 1024 * 1024 || ! in_array($file->getMimeType(), $allowed, true)) {
                return response()->json(['ok' => false, 'message' => 'Only PDF, Office, text, and image files up to 25 MB are allowed.'], 422);
            }
        }
        $movedPaths = [];
        $deletePaths = [];
        try {
            $record = DB::transaction(function () use ($projectId, $incoming, $deleteIds, $files, $user, &$movedPaths, &$deletePaths) {
                $row = DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
                if (! $row) {
                    throw new \DomainException('This project no longer exists. Refresh and try again.');
                }
                $before = json_decode($row->data, true) ?: [];
                $savedVersion = (string) ($before['updatedAt'] ?? '');
                $clientVersion = (string) ($incoming['updatedAt'] ?? '');
                if ($savedVersion !== '' && ($clientVersion === '' || ! hash_equals($savedVersion, $clientVersion))) {
                    throw new \DomainException('This project was updated by another user. Refresh before saving.');
                }
                \App\Support\TrackerProjectInput::validateDates(array_replace($before, $incoming), $before);
                $record = $incoming;
                unset($record['id'], $record['actor'], $record['attachments'], $record['archivedAt'], $record['archivedBy']);
                $record = \App\Support\TrackerProjectInput::normalize(array_replace($before, $record));
                \App\Support\TrackerProjectInput::validateAlertPermission($record,$before,!empty($user['canResolveAlerts']));
                \App\Support\TrackerProjectInput::validateManualResolution($record, $before);
                if (($record['statusMode'] ?? '') !== 'manual' && strtolower(trim((string) ($record['status'] ?? ''))) === 'completed' && ! TrackerDataController::validProjectDate($record['completion'] ?? null)) {
                    $legacyUndated = strtolower(trim((string) ($before['status'] ?? ''))) === 'completed'
                        && trim((string) ($before['completion'] ?? '')) === ''
                        && trim((string) ($record['completion'] ?? '')) === '';
                    if (! $legacyUndated) {
                        throw new \InvalidArgumentException('Completed projects require a valid Completion Date.');
                    }
                }
                $attachments = array_values(array_filter($before['attachments'] ?? [], fn ($item) => is_array($item) && ! empty($item['id']) && ! empty($item['storedName'])));
                foreach ($deleteIds as $deleteId) {
                    $match = collect($attachments)->first(fn ($item) => hash_equals((string) $item['id'], (string) $deleteId));
                    if (! $match) {
                        throw new \InvalidArgumentException('An attachment to remove was not found. Refresh and try again.');
                    }
                    $deletePaths[] = $projectId.'/'.basename((string) $match['storedName']);
                    $attachments = array_values(array_filter($attachments, fn ($item) => ! hash_equals((string) $item['id'], (string) $deleteId)));
                }
                foreach ($files as $file) {
                    $mime = $file->getMimeType();
                    $size = $file->getSize();
                    $name = mb_substr(basename($file->getClientOriginalName()), 0, 255);
                    $attachmentId = md5(Str::uuid()->toString());
                    $stored = $attachmentId.($file->getClientOriginalExtension() ? '.'.preg_replace('/[^a-z0-9]/', '', strtolower($file->getClientOriginalExtension())) : '');
                    TrackerMediaStore::upload('attachments', $projectId.'/'.$stored, $file);
                    $movedPaths[] = $projectId.'/'.$stored;
                    $attachments[] = ['id' => $attachmentId, 'name' => $name, 'storedName' => $stored, 'mime' => $mime, 'size' => $size, 'uploadedAt' => now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'uploadedBy' => $user['username']];
                }
                $record['attachments'] = $attachments;
                $record['projectNo'] = $before['projectNo'] ?? $record['projectNo'] ?? '';
                $record['updatedBy'] = $user['username'];
                $record['updatedAt'] = now('UTC')->format('Y-m-d\TH:i:s.u\Z');
                DB::table('projects')->where('id', $projectId)->update(['data' => json_encode($record, JSON_UNESCAPED_UNICODE)] + TrackerDataController::projectColumns($record) + ['updated_by' => $user['id'], 'updated_at' => now()]);
                TrackerHistory::append($projectId, $before, $record, $user, 'Edited');
                \App\Support\TrackerAudit::record(['actor_id' => $user['id'], 'actor_username' => $user['username'], 'action' => 'project.saved', 'entity_type' => 'project', 'entity_id' => $projectId, 'summary' => 'Saved project: '.$record['title'], 'created_at' => now()], ['before' => $before, 'after' => $record]);

                return $record;
            });
        } catch (\Throwable $error) {
            foreach ($movedPaths as $path) {
                try { TrackerMediaStore::delete('attachments', $path); } catch (\Throwable $cleanupError) { report($cleanupError); }
            }
            if ($error instanceof \DomainException || $error instanceof \InvalidArgumentException) {
                return response()->json(['ok' => false, 'message' => $error->getMessage()], $error instanceof \DomainException ? 409 : 422);
            }
            throw $error;
        }
        foreach ($deletePaths as $path) {
            try { TrackerMediaStore::delete('attachments', $path); } catch (\Throwable $cleanupError) { report($cleanupError); }
        }
        $record['id'] = $projectId;

        return response()->json(['ok' => true, 'record' => $record]);
    }

    public function attachment(Request $request): JsonResponse|BinaryFileResponse|StreamedResponse
    {
        $user = (array) $request->session()->get('tracker_user');
        $request->validate(['projectId'=>['required','integer','min:1'],'attachmentId'=>['sometimes','nullable','string'],'action'=>['sometimes','nullable','string']]);
        $projectId = (int) $request->input('projectId', $request->query('projectId'));
        $row = DB::table('projects')->where('id', $projectId)->first();
        if (! $row) {
            return response()->json(['ok' => false, 'message' => 'Project not found.'], 404);
        }
        $project = json_decode($row->data, true) ?: [];
        $attachments = array_values(array_filter($project['attachments'] ?? [], fn ($item) => is_array($item) && ! empty($item['id']) && ! empty($item['storedName'])));

        if ($request->isMethod('get')) {
            $id = (string) $request->query('attachmentId', '');
            $item = collect($attachments)->first(fn ($a) => hash_equals((string) $a['id'], $id));
            if (! $item) {
                return response()->json(['ok' => false, 'message' => 'Attachment not found.'], 404);
            }
            $path = $projectId.'/'.basename((string) $item['storedName']);
            if (! TrackerMediaStore::exists('attachments', $path)) {
                return response()->json(['ok' => false, 'message' => 'Attachment file is unavailable.'], 404);
            }

            $name = basename(str_replace('\\','/',(string)($item['name'] ?? 'attachment')));
            $name = str_replace(["\r","\n","\0"],'',$name) ?: 'attachment';
            return TrackerMediaStore::response('attachments', $path, ['Content-Type'=>$item['mime'] ?? 'application/octet-stream','X-Content-Type-Options'=>'nosniff', 'Cache-Control'=>'private, no-store'], $name, $request->query('download') === '1' ? 'attachment' : 'inline');

        }

        if (empty($user['canEdit'])) {
            return response()->json(['ok' => false, 'message' => 'You do not have permission to manage attachments.'], 403);
        }
        $action = (string) $request->input('action');
        if (!in_array($action,['upload','delete'],true)) return response()->json(['ok'=>false,'message'=>'Unsupported attachment request.'],400);
        if ($action === 'upload' && !$request->file('files')) return response()->json(['ok'=>false,'message'=>'Choose at least one file to upload.'],422);
        $request->merge([
            'projectId'=>$projectId,
            'record'=>json_encode($project, JSON_UNESCAPED_UNICODE),
            'deleteIds'=>json_encode($action === 'delete' ? [(string)$request->input('attachmentId')] : []),
        ]);
        $response = $this->saveProjectEdit($request);
        $payload = $response->getData(true);
        return !empty($payload['ok']) ? response()->json(['ok'=>true,'attachments'=>$payload['record']['attachments']]) : $response;
    }

    public function profile(Request $request): JsonResponse|BinaryFileResponse|StreamedResponse
    {
        $user = (array) $request->session()->get('tracker_user');
        if ($request->isMethod('get')) {
            $request->validate(['userId'=>['sometimes','integer','min:1']]);
            $id = (int) $request->query('userId', $user['id']);
            if ($id !== (int) $user['id'] && ($user['role'] ?? '') !== 'admin') {
                return response()->json(['ok' => false, 'message' => 'Not allowed.'], 403);
            }
            $photo = basename((string) DB::table('users')->where('id', $id)->value('profile_photo'));
            if ($photo === '' || ! TrackerMediaStore::exists('profile_photos', $photo)) {
                return response()->json(['ok' => false, 'message' => 'Profile photo not found.'], 404);
            }

            return TrackerMediaStore::response('profile_photos', $photo, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options'=>'nosniff']);
        }
        $request->validate(['fullName'=>['required','string','max:160'],'photo'=>['sometimes','file']]);
        $fullName = trim((string) $request->input('fullName'));
        if ($fullName === '' || mb_strlen($fullName) > 160) {
            return response()->json(['ok' => false, 'message' => 'Enter a display name of up to 160 characters.'], 422);
        }
        $photoName = null;
        $photo = $request->file('photo');
        if ($photo) {
            $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!$photo->isValid() || $photo->getSize() < 1 || $photo->getSize() > 3*1024*1024 || !isset($extensions[$photo->getMimeType()])) {
                return response()->json(['ok'=>false,'message'=>'Use a JPG, PNG, or WebP image smaller than 3 MB.'],422);
            }
            $photoName=$user['id'].'-'.Str::random(24).'.'.$extensions[$photo->getMimeType()];
            TrackerMediaStore::upload('profile_photos', $photoName, $photo);
        }
        $previous = '';
        try {
            $savedName = DB::transaction(function () use ($user,$fullName,$photoName,&$previous) {
                $account=DB::table('users')->where('id',$user['id'])->lockForUpdate()->first();
                if (!$account) throw new \RuntimeException('The account no longer exists.');
                $previous=basename((string)$account->profile_photo);
                $values=['full_name'=>$fullName];
                // A name-only update must retain a concurrent photo change.
                if ($photoName !== null) $values += ['profile_photo'=>$photoName,'profile_photo_updated_at'=>now()];
                DB::table('users')->where('id',$user['id'])->update($values);
                return $photoName ?? $previous;
            });
        } catch (\Throwable $error) {
            if ($photoName !== null) { try { TrackerMediaStore::delete('profile_photos', $photoName); } catch (\Throwable $cleanupError) { report($cleanupError); } }
            throw $error;
        }
        if ($photoName !== null && $previous) { try { TrackerMediaStore::delete('profile_photos', $previous); } catch (\Throwable $cleanupError) { report($cleanupError); } }
        $photoName=$savedName;
        $user['fullName'] = $fullName;
        $user['profilePhoto'] = $photoName ? 'api/profile.php?action=photo&v='.rawurlencode(now('UTC')->format('YmdHis.u')) : null;
        $request->session()->put('tracker_user', $user);

        return response()->json(['ok' => true, 'user' => $user, 'message' => 'Profile updated.']);
    }
}
