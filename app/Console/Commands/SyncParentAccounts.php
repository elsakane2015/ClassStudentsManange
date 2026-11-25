<?php

namespace App\Console\Commands;

use App\Services\ParentAccountSyncService;
use Illuminate\Console\Command;

class SyncParentAccounts extends Command
{
    protected $signature = 'parents:sync-accounts';

    protected $description = '将学生信息中的家长邮箱同步为父母端账号与关联关系';

    public function handle(ParentAccountSyncService $syncService): int
    {
        $result = $syncService->syncAllStudents();

        $this->info(sprintf(
            '同步完成：共处理 %d 名学生，新创建 %d 个父母端账号。',
            $result['students_processed'],
            $result['parent_accounts_created']
        ));

        return self::SUCCESS;
    }
}
