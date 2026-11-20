<?php
namespace App\Support;

final class TrackerProjectFields
{
    private const MODEL = <<<'MODEL'
{
  "sections": [
    {
      "id": "basics",
      "label": "Project Details"
    },
    {
      "id": "procurement",
      "label": "Procurement Details"
    },
    {
      "id": "contract",
      "label": "Award Details"
    },
    {
      "id": "inspection",
      "label": "Inspection Details"
    },
    {
      "id": "materials",
      "label": "Material Testing"
    },
    {
      "id": "damages",
      "label": "Liquidated Damages"
    },
    {
      "id": "completion",
      "label": "Completion Details"
    }
  ],
  "fields": [
    {
      "section": "basics",
      "key": "referenceNo",
      "id": "referenceNo",
      "label": "Reference #",
      "type": "text",
      "max": 255,
      "required": false
    },
    {
      "section": "basics",
      "key": "title",
      "id": "title",
      "label": "Project Title",
      "type": "textarea",
      "max": 400,
      "required": true
    },
    {
      "section": "basics",
      "key": "approvedBudget",
      "id": "approvedBudget",
      "label": "Approved Budget Cost",
      "type": "money",
      "max": 255,
      "required": true
    },
    {
      "section": "basics",
      "key": "sourceOfFund",
      "id": "sourceOfFund",
      "label": "Source of Fund",
      "type": "fund",
      "max": 255,
      "required": false
    },
    {
      "section": "basics",
      "key": "bidAmount",
      "id": "bidAmount",
      "label": "Bid Amount",
      "type": "money",
      "max": 255,
      "required": false
    },
    {
      "section": "basics",
      "key": "winningBidder",
      "id": "winningBidder",
      "label": "Contractor",
      "type": "text",
      "max": 255,
      "required": false
    },
    {
      "section": "basics",
      "key": "owner",
      "id": "owner",
      "label": "Owner",
      "type": "text",
      "max": 255,
      "required": false
    },
    {
      "section": "basics",
      "key": "address",
      "id": "address",
      "label": "Address",
      "type": "textarea",
      "max": 2000,
      "required": false
    },
    {
      "section": "procurement",
      "key": "preProcurementDate",
      "id": "preProcurementDate",
      "label": "Pre-Procurement Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "preBidDate",
      "id": "preBidDate",
      "label": "Pre-Bid Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "biddingDate",
      "id": "biddingDate",
      "label": "Date of Bidding",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "prNumber",
      "id": "prNumber",
      "label": "PR #",
      "type": "text",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "prDate",
      "id": "prDate",
      "label": "PR Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "noticePostQual",
      "id": "noticePostQual",
      "label": "Post-Qualification Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "resolutionNumber",
      "id": "resolutionNumber",
      "label": "Resolution #",
      "type": "digits",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "resolutionDate",
      "id": "resolutionDate",
      "label": "Resolution Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "obreNumber",
      "id": "obreNumber",
      "label": "OBRE #",
      "type": "digits",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "obre",
      "id": "obre",
      "label": "OBRE Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "endUserOffice",
      "id": "endUserOffice",
      "label": "End User — Office",
      "type": "text",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "endUserName",
      "id": "endUserName",
      "label": "End User — Name",
      "type": "text",
      "max": 255
    },
    {
      "section": "procurement",
      "key": "endUserPosition",
      "id": "endUserPosition",
      "label": "End User — Position",
      "type": "text",
      "max": 255
    },
    {
      "section": "contract",
      "key": "noa",
      "id": "noa",
      "label": "Notice of Award",
      "type": "date",
      "max": 255,
      "readonly": false
    },
    {
      "section": "contract",
      "key": "contractRef",
      "id": "contractRef",
      "label": "Contract Agreement",
      "type": "date",
      "max": 255,
      "readonly": false
    },
    {
      "section": "contract",
      "key": "ntpDate",
      "id": "ntpDate",
      "label": "Notice to Proceed",
      "type": "date",
      "max": 255,
      "readonly": false
    },
    {
      "section": "contract",
      "key": "contractDuration",
      "id": "contractDuration",
      "label": "Contract Duration (working days)",
      "type": "number",
      "max": 255,
      "readonly": false
    },
    {
      "section": "contract",
      "key": "contractEndDate",
      "id": "contractEndDate",
      "label": "Contract End Date",
      "type": "date",
      "max": 255,
      "readonly": true
    },
    {
      "section": "contract",
      "key": "noaDueDate",
      "id": "noaDueDate",
      "label": "NOA Due Date",
      "type": "date",
      "max": 255,
      "readonly": false
    },
    {
      "section": "inspection",
      "key": "preInspection",
      "id": "preInspection",
      "label": "Pre-Inspection Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "inspection",
      "key": "preInspectionRemarks",
      "id": "preInspectionRemarks",
      "label": "Pre-Inspection Remarks",
      "type": "textarea",
      "max": 4000
    },
    {
      "section": "inspection",
      "key": "finalInspection",
      "id": "finalInspection",
      "label": "Final Inspection Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "inspection",
      "key": "finalInspectionRemarks",
      "id": "finalInspectionRemarks",
      "label": "Final Inspection Remarks",
      "type": "textarea",
      "max": 4000
    },
    {
      "section": "inspection",
      "key": "physicalAccomplishment",
      "id": "physicalAccomplishment",
      "label": "Physical Accomplishment",
      "type": "percentage",
      "max": 255
    },
    {
      "section": "inspection",
      "key": "physicalAccomplishmentRemarks",
      "id": "physicalAccomplishmentRemarks",
      "label": "Physical Accomplishment Remarks",
      "type": "textarea",
      "max": 4000
    },
    {
      "section": "materials",
      "key": "materialTestingDate",
      "id": "materialTestingDate",
      "label": "Testing Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material1",
      "id": "material1",
      "label": "Material 1",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material2",
      "id": "material2",
      "label": "Material 2",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material3",
      "id": "material3",
      "label": "Material 3",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material4",
      "id": "material4",
      "label": "Material 4",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material5",
      "id": "material5",
      "label": "Material 5",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material6",
      "id": "material6",
      "label": "Material 6",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material7",
      "id": "material7",
      "label": "Material 7",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material8",
      "id": "material8",
      "label": "Material 8",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material9",
      "id": "material9",
      "label": "Material 9",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "material10",
      "id": "material10",
      "label": "Material 10",
      "type": "text",
      "max": 255
    },
    {
      "section": "materials",
      "key": "materialTestingAmount",
      "id": "materialTestingAmount",
      "label": "Testing Amount",
      "type": "money",
      "max": 255
    },
    {
      "section": "materials",
      "key": "materialRequestDate",
      "id": "materialRequestDate",
      "label": "Request Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "materials",
      "key": "materialTestingRemarks",
      "id": "materialTestingRemarks",
      "label": "Material Testing Remarks",
      "type": "textarea",
      "max": 4000
    },
    {
      "section": "damages",
      "key": "ldAmount",
      "id": "ldAmount",
      "label": "Amount",
      "type": "flexMoney",
      "max": 255
    },
    {
      "section": "damages",
      "key": "ldNoOfDays",
      "id": "ldNoOfDays",
      "label": "Number of Days",
      "type": "text",
      "max": 255
    },
    {
      "section": "damages",
      "key": "ldNewCompletionDate",
      "id": "ldNewCompletionDate",
      "label": "New Completion Date",
      "type": "date",
      "max": 255
    },
    {
      "section": "damages",
      "key": "ldActualDuration",
      "id": "ldActualDuration",
      "label": "Actual Duration",
      "type": "number",
      "max": 255
    },
    {
      "section": "damages",
      "key": "ldRemarks",
      "id": "ldRemarks",
      "label": "Liquidated Damages Remarks",
      "type": "textarea",
      "max": 4000
    },
    {
      "section": "completion",
      "key": "completion",
      "id": "completionDate",
      "label": "Completion Date",
      "type": "date",
      "max": 255,
      "hidden": false
    },
    {
      "section": "completion",
      "key": "swa",
      "id": "swa",
      "label": "SWA Date",
      "type": "date",
      "max": 255,
      "hidden": false
    },
    {
      "section": "completion",
      "key": "swaRemarks",
      "id": "swaRemarks",
      "label": "SWA Remarks",
      "type": "textarea",
      "max": 4000,
      "hidden": false
    },
    {
      "section": "completion",
      "key": "statusMode",
      "id": "statusMode",
      "label": "Status Mode",
      "type": "mode",
      "max": 255,
      "hidden": false
    },
    {
      "section": "completion",
      "key": "status",
      "id": "status",
      "label": "Operational Status",
      "type": "status",
      "max": 255,
      "hidden": false
    },
    {
      "section": "completion",
      "key": "manualStatus",
      "id": "manualStatus",
      "label": "Manual Status",
      "type": "text",
      "max": 255,
      "hidden": true
    },
    {
      "section": "basics",
      "key": "losingBidder1",
      "id": "losingBidder1",
      "label": "Losing Bidder 1",
      "type": "text",
      "max": 255,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "losingBidAmount1",
      "id": "losingBidAmount1",
      "label": "Losing Bid Amount 1",
      "type": "flexMoney",
      "max": 255,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "losingBidder2",
      "id": "losingBidder2",
      "label": "Losing Bidder 2",
      "type": "text",
      "max": 255,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "losingBidAmount2",
      "id": "losingBidAmount2",
      "label": "Losing Bid Amount 2",
      "type": "flexMoney",
      "max": 255,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "approvedBudgetWords",
      "id": "approvedBudgetWords",
      "label": "ABC (in words)",
      "type": "textarea",
      "max": 4000,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "bidAmountWords",
      "id": "bidAmountWords",
      "label": "Bid Amount (in words)",
      "type": "textarea",
      "max": 4000,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "taker",
      "id": "taker",
      "label": "Taker",
      "type": "text",
      "max": 255,
      "legacy": true
    },
    {
      "section": "basics",
      "key": "remarks",
      "id": "remarks",
      "label": "General Remarks",
      "type": "textarea",
      "max": 4000,
      "legacy": true
    },
    {
      "section": "procurement",
      "key": "philgeps",
      "id": "philgeps",
      "label": "PhilGEPS",
      "type": "text",
      "max": 255,
      "legacy": true
    },
    {
      "section": "contract",
      "key": "performanceBond",
      "id": "performanceBond",
      "label": "Performance Bond",
      "type": "date",
      "max": 255,
      "legacy": true
    },
    {
      "section": "contract",
      "key": "dole",
      "id": "dole",
      "label": "DOLE Clearance",
      "type": "date",
      "max": 255,
      "legacy": true
    },
    {
      "section": "damages",
      "key": "liquidatedDamages",
      "id": "liquidatedDamages",
      "label": "Liquidated Damages (source)",
      "type": "text",
      "max": 2000,
      "legacy": true
    },
    {
      "section": "completion",
      "key": "completionSwaDate",
      "id": "completionSwaDate",
      "label": "Completion/SWA Date (source)",
      "type": "text",
      "max": 255,
      "legacy": true
    },
    {
      "section": "completion",
      "key": "releasedBy",
      "id": "releasedBy",
      "label": "Released by",
      "type": "text",
      "max": 255,
      "legacy": true
    }
  ]
}
MODEL;
    public static function model(): array { static $model; return $model ??= json_decode(self::MODEL, true, flags: JSON_THROW_ON_ERROR); }
    public static function fields(): array { return self::model()['fields']; }
    public static function sections(): array { return self::model()['sections']; }
    public static function columns(): array {
        static $cached;
        if ($cached !== null) return $cached;
        $columns = ['projectNo'=>'Project No.'];
        foreach (self::fields() as $field) $columns[$field['key']] = $field['label'];
        return $cached = $columns;
    }
    public static function dates(): array { static $dates; return $dates ??= array_column(array_filter(self::fields(), fn($f)=>$f['type']==='date'), 'key'); }
    public static function isNotApplicable(array $record): bool { return strtoupper(trim((string)($record['ldAmount'] ?? ''))) === 'N/A'; }
    public static function display(array $record, string $key): mixed {
        if (self::isNotApplicable($record) && in_array($key,['ldAmount','ldNoOfDays','ldNewCompletionDate','ldActualDuration','ldRemarks'],true)) return 'N/A';
        if ($key === 'physicalAccomplishment' && ($record[$key] ?? '') !== '' && is_numeric($record[$key])) return (float)$record[$key] === 0.0 ? 'NYS' : (string)$record[$key].'%';
        return $record[$key] ?? '';
    }
}
