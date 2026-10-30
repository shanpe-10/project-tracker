<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (\Illuminate\Support\Facades\DB::connection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION tracker_touch_updated_at() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_TABLE_NAME = 'projects' THEN
  IF (NEW.data - 'status') = (OLD.data - 'status')
     AND (to_jsonb(NEW) - ARRAY['data','project_status','updated_at']) = (to_jsonb(OLD) - ARRAY['data','project_status','updated_at']) THEN
   NEW.updated_at = OLD.updated_at; RETURN NEW;
  END IF;
 END IF;
 NEW.updated_at = CURRENT_TIMESTAMP; RETURN NEW;
END $$;
SQL);
        }
        if (!Schema::hasTable('import_staging_chunks')) Schema::create('import_staging_chunks', function (Blueprint $table) {
            $table->string('batch_id',64);
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('expected_rows')->nullable();
            $table->boolean('archive')->default(false);
            $table->json('records');
            $table->primary(['batch_id','chunk_index']);
        });
    }
    public function down(): void { Schema::dropIfExists('import_staging_chunks'); }
};
