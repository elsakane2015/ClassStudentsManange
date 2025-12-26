<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanupLeaveImages extends Command
{
    protected $signature = 'leave-images:cleanup {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Clean up old leave images based on retention settings';

    public function handle()
    {
        // Check if auto cleanup is enabled
        $autoCleanup = SystemSetting::get('leave_image_auto_cleanup', 'true');
        if ($autoCleanup !== 'true') {
            $this->info('Auto cleanup is disabled. Skipping.');
            return 0;
        }

        $retentionDays = (int) SystemSetting::get('leave_image_retention_days', '90');
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        $dryRun = $this->option('dry-run');

        $this->info("Cleaning up leave images older than {$retentionDays} days (before {$cutoffDate->toDateString()})");
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No files will be deleted');
        }

        // Find records with images that are older than retention period
        $records = AttendanceRecord::whereNotNull('images')
            ->where('created_at', '<', $cutoffDate)
            ->get();

        $deletedCount = 0;
        $totalImages = 0;

        foreach ($records as $record) {
            $images = $record->images;
            if (!is_array($images) || empty($images)) {
                continue;
            }

            $totalImages += count($images);

            foreach ($images as $imagePath) {
                if (Storage::disk('public')->exists($imagePath)) {
                    if (!$dryRun) {
                        Storage::disk('public')->delete($imagePath);
                    }
                    $deletedCount++;
                    $this->line("  - {$imagePath}");
                }
            }

            // Clear images from record
            if (!$dryRun) {
                $record->update(['images' => null]);
            }
        }

        $this->info("Found {$totalImages} images in {$records->count()} records");
        $this->info("Deleted {$deletedCount} image files");

        return 0;
    }
}
