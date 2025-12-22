<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StudentTemplateExport implements FromArray, WithHeadings
{
    public function array(): array
    {
        return [
            ['计算机系', '2024级', '软件1班', '张三', 'zhangsan@demo.com', '123456', '2024001', 'male', '2010-01-01', '13800000000'],
            ['外语系', '2023级', '英语2班', '李四', 'lisi@demo.com', '123456', '2024002', 'female', '2010-05-20', '13900000000'],
        ];
    }

    public function headings(): array
    {
        return ['department_name', 'grade_name', 'class_name', 'name', 'email', 'password', 'student_no', 'gender', 'birthdate', 'parent_contact'];
    }
}
