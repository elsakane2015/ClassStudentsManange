<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentTemplateExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [
            ['计算机系', '2024级', '软件1班', '张三', 'zhangsan@demo.com', '123456', '2024001', '男', '2010-01-01', '13800000000', 'parent.zhang@example.com, guardian.zhang@example.com', '是'],
            ['外语系', '2023级', '英语2班', '李四', 'lisi@demo.com', '123456', '2024002', '女', '2010-05-20', '13900000000', 'parent.li@example.com', '否'],
        ];
    }

    public function headings(): array
    {
        return ['系部名称', '年级名称', '班级名称', '学生姓名', '账号邮箱', '初始密码', '学号', '性别', '出生日期', '家长联系方式', '家长邮箱', '是否住宿生'];
    }
}
