# 夜自习请假与点名功能实施方案

## 1. 目标

在现有考勤体系中增加住宿生夜自习请假和点名能力，继续复用统一的学生、请假类型、审批和考勤记录模型。

核心目标：

- 学生继续使用现有请假入口，不增加“普通考勤 / 夜自习”切换。
- 系统管理员在“考勤规则 -> 节次管理”中配置夜自习节次。
- 每个节次可配置适用于全体学生还是仅住宿生。
- 夜自习申请和点名结果继续写入 `attendance_records`。
- 每条考勤记录保存“是否计入白天考勤统计”的快照。
- 只有住宿生能申请夜自习请假并进入夜自习点名名单。
- 住宿生被临时暂停住宿许可时仍保留住宿生身份，并在值班点名中明确展示暂停状态。
- 班主任可审批学生申请，也可直接代学生标记夜自习请假。
- 系部值班老师按班级和夜自习节次点名，名单默认正常。
- 夜自习状态由系统管理员自由设置，不通过名称硬编码。
- 白天考勤、夜自习考勤可以共用数据源，但分别统计和展示。

## 2. 总体设计

采用以下结构：

1. 节次配置决定适用学生、业务场景以及是否计入白天统计。
2. `attendance_records` 继续作为普通请假、夜自习请假和最终考勤结果的统一数据源。
3. `evening_study_statuses` 保存可配置的夜自习状态。
4. `evening_study_sessions` 只负责记录一次点名任务是否执行、由谁执行以及汇总数据。
5. `boarding_suspensions` 保存住宿生临时暂停住宿许可的日期范围和操作历史。
6. 不新增独立的夜自习请假表，也不新增独立的夜自习点名明细表。

数据关系：

```text
节次配置 attendance_periods
        |
        +---- scene / audience_scope / counts_in_day_stats
        |
学生请假入口 ---- attendance_records ---- 夜自习状态
                         |
                         +---- 普通请假审批
                         +---- 夜自习请假审批
                         +---- 值班点名最终结果
                         |
                 evening_study_sessions
```

## 3. 已确认的业务原则

### 3.1 一个请假入口

学生仍使用现有“新建请假申请”页面：

- 走读生只看到适用于全体学生的节次和时段。
- 住宿生额外看到适用于住宿生的夜自习节次。
- 页面不显示“普通考勤 / 夜自习”模式切换。
- 时段只是节次的快速选择预设；“全天”可以同时关联普通节次和夜自习节次。
- 同一次申请允许同时选择普通节次和夜自习节次，并使用一个 `leave_batch_id` 提交和审批。
- 学生选择节次后，页面根据节次配置自动改变表单。
- 后端读取真实节次配置决定业务场景，不能信任前端自行声明 `scene` 或统计开关。

### 3.2 节次配置驱动

夜自习在“系统设置 -> 考勤规则 -> 节次管理”中创建，不创建独立的夜自习时段。管理员可以在“时段管理”中把夜自习节次关联到“全天”等现有时段预设。

现有普通节次默认：

- 适用范围：全体学生。
- 业务场景：普通考勤。
- 计入白天考勤统计：是。

夜自习节次建议：

- 类型：特殊节次。
- 适用范围：仅住宿生。
- 业务场景：夜自习。
- 计入白天考勤统计：否。

管理员可以增加多个夜自习节次，例如第一节夜自习、第二节夜自习。

### 3.3 统一记录、分类统计

夜自习记录继续写入 `attendance_records`，但通过以下字段与白天考勤区分：

- `scene = evening_study`
- `counts_in_day_stats = false`
- `period_id = 对应夜自习节次 ID`

白天仪表盘、日历、折算和导出只读取 `counts_in_day_stats = true` 的记录。

夜自习历史和统计只读取 `scene = evening_study` 的记录。

### 3.4 住宿生限制

夜自习必须同时进行前后端限制：

- 学生端仅在 `students.is_boarding = true` 时展示住宿生专用节次。
- 学生提交时，后端再次校验学生为住宿生。
- 班主任只能给自己班级中的住宿生标记夜自习请假。
- 值班老师创建点名场次时只加载该班当前住宿生。
- 临时暂停住宿许可不会将 `is_boarding` 改为 `false`，暂停学生仍出现在值班点名名单中。
- 暂停期间，学生行显示“暂停住宿”、有效日期、原因和班主任信息。
- 点名记录创建后保存学生和状态快照，学生以后转为走读生不改变历史。

## 4. 节次配置

当前 `attendance_periods` 保存于 `system_settings` JSON 中。每个节次增加以下字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | integer | 稳定节次 ID |
| `name` | string | 节次名称 |
| `type` | string | 现有 `regular` 或 `special` |
| `order` | integer | 排序 |
| `audience_scope` | string | `all` 或 `boarding` |
| `scene` | string | `regular` 或 `evening_study` |
| `counts_in_day_stats` | boolean | 是否计入白天考勤统计 |
| `is_active` | boolean | 是否启用 |

夜自习示例：

```json
{
  "id": 10,
  "name": "夜自习",
  "type": "special",
  "order": 9,
  "audience_scope": "boarding",
  "scene": "evening_study",
  "counts_in_day_stats": false,
  "is_active": true
}
```

### 4.1 兼容旧配置

读取旧节次配置时使用默认值：

```text
audience_scope = all
scene = regular
counts_in_day_stats = true
is_active = true
```

保存系统设置后可将默认字段补齐，避免现有节次行为变化。

### 4.2 删除和停用

节次一旦被考勤记录、点名类型或夜自习场次引用，不应直接删除 ID。

- 已有历史引用：只能停用。
- 没有历史引用：可以删除。
- 停用后不再出现在新申请和新点名中。
- 历史页面仍按记录快照显示原节次名称。

## 5. 夜自习状态配置

建立 `evening_study_statuses`，由系统管理员在系统设置中维护。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint | 主键 |
| `school_id` | bigint | 学校 |
| `name` | string | 状态名称 |
| `color` | string | 显示颜色 |
| `base_status` | string | 映射到现有考勤状态，如 `present / absent / excused` |
| `is_default` | boolean | 是否为默认正常状态 |
| `student_requestable` | boolean | 学生是否可在夜自习申请中选择 |
| `leave_type_id` | nullable bigint | 可选关联现有系统请假类型 |
| `is_active` | boolean | 是否启用 |
| `sort_order` | integer | 排序 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

### 5.1 状态约束

- 每所学校只能有一个启用的默认状态。
- 默认状态的 `base_status` 必须为 `present`。
- 学生可申请状态必须关联一个启用且允许学生申请的请假类型。
- 已有历史引用的状态只能停用，不能物理删除。
- 业务逻辑不得根据“正常”“病假”等文字判断状态含义。

### 5.2 初始状态

| 状态 | 基础状态 | 默认 | 学生可申请 | 建议关联类型 |
| --- | --- | --- | --- | --- |
| 正常 | `present` | 是 | 否 | 无 |
| 病假在宿舍 | `excused` | 否 | 是 | 病假 |
| 病假在家 | `excused` | 否 | 是 | 病假 |
| 在学生会 | `excused` | 否 | 是 | 其他/活动 |
| 在图书馆 | `excused` | 否 | 是 | 其他/活动 |
| 暂停住宿 | `excused` | 否 | 否 | 无 |
| 未到 | `absent` | 否 | 否 | 无 |

这些状态只作为首次安装的基础数据，管理员可以改名、排序、停用或增加其他状态。

## 6. 统一考勤记录扩展

扩展 `attendance_records`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `scene` | string | `regular` 或 `evening_study`，默认 `regular` |
| `counts_in_day_stats` | boolean | 是否计入白天统计，默认 `true` |
| `period_name_snapshot` | nullable string | 创建记录时的节次名称 |
| `requested_evening_status_id` | nullable bigint | 学生申请的夜自习状态 |
| `requested_status_name_snapshot` | nullable string | 申请状态名称快照 |
| `evening_study_status_id` | nullable bigint | 最终点名状态 |
| `status_name_snapshot` | nullable string | 最终状态名称快照 |
| `destination` | nullable text | 具体去向 |
| `evening_study_session_id` | nullable bigint | 所属夜自习场次 |
| `boarding_suspension_id` | nullable bigint | 点名时生效的暂停住宿许可记录 |
| `manually_overridden_at` | nullable timestamp | 值班老师手工覆盖时间 |

### 6.1 为什么区分申请状态和最终状态

同一条记录可能同时包含：

- 学生申请“病假在家”，当前仍待审批。
- 值班老师现场确认学生实际在宿舍或未到。

因此不能只使用一个夜自习状态字段：

- `requested_evening_status_id` 保存申请内容。
- `evening_study_status_id` 保存最终点名结果。
- 审批状态继续使用现有 `approval_status`。
- 值班老师手工结果不能覆盖或删除原申请内容。

### 6.2 现有字段继续复用

- `student_id`：学生。
- `school_id` / `class_id`：学校和班级。
- `date`：考勤日期。
- `period_id`：夜自习节次。
- `status`：由最终夜自习状态的 `base_status` 映射。
- `leave_type_id`：系统请假类型。
- `is_self_applied`：学生申请或教师标记。
- `approval_status`：待审批、批准、驳回。
- `approver_id` / `approved_at`：审批信息。
- `reason`：申请理由。
- `source_type` / `source_id`：来源和场次关联。
- `leave_batch_id`：多日期申请批次。

### 6.3 统计快照

`counts_in_day_stats` 在记录创建时从节次配置复制，之后不随管理员修改节次配置而变化。

这样可避免管理员修改节次开关后，过去的白天统计突然发生变化。

已有考勤记录迁移默认：

```text
scene = regular
counts_in_day_stats = true
```

## 7. 夜自习点名场次

只新增 `evening_study_sessions`，不新增点名明细表。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint | 主键 |
| `school_id` | bigint | 学校 |
| `department_id` | bigint | 系部快照 |
| `class_id` | bigint | 班级 |
| `period_id` | integer | 夜自习节次 ID |
| `period_name_snapshot` | string | 节次名称快照 |
| `attendance_date` | date | 点名日期 |
| `created_by` | bigint | 值班老师 |
| `status` | string | `in_progress / completed / cancelled` |
| `total_students` | integer | 本次住宿生总数 |
| `normal_count` | integer | 默认正常状态人数 |
| `exception_count` | integer | 非默认状态人数 |
| `notes` | nullable text | 场次备注 |
| `completed_at` | nullable timestamp | 完成时间 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

唯一约束：

```text
attendance_date + class_id + period_id
```

场次中的学生明细直接通过以下条件读取：

```text
attendance_records.evening_study_session_id = sessions.id
```

## 8. 临时暂停住宿许可

`students.is_boarding` 表示学生的长期住宿生身份，不能用于记录临时暂停。临时暂停需要独立的历史表 `boarding_suspensions`。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint | 主键 |
| `student_id` | bigint | 住宿生 |
| `school_id` | bigint | 学校 |
| `class_id` | bigint | 创建时班级快照 |
| `start_date` | date | 暂停开始日期，包含当天 |
| `end_date` | date | 暂停结束日期，包含当天 |
| `reason` | text | 暂停原因，必填 |
| `destination` | nullable text | 暂停期间去向 |
| `created_by` | bigint | 标记该状态的班主任或管理员 |
| `revoked_at` | nullable timestamp | 提前恢复住宿许可时间 |
| `revoked_by` | nullable bigint | 提前恢复操作人 |
| `revoke_reason` | nullable text | 提前恢复说明 |
| `created_at` / `updated_at` | timestamp | 时间戳 |

### 8.1 生效规则

某个日期处于暂停状态，需要同时满足：

```text
start_date <= attendance_date <= end_date
revoked_at IS NULL
```

- 只有当前住宿生可以被暂停住宿许可。
- 同一学生不能存在日期范围重叠且未撤销的暂停记录。
- 只允许班主任操作自己班级学生；系部、校级和系统管理员按权限操作。
- 暂停记录不能物理删除，只能提前恢复，保留完整操作历史。
- 学生长期转为走读生时，仍保留过去的暂停记录。

### 8.2 与夜自习状态关联

系统设置保存 `boarding_suspension_status_id`，由管理员从启用的夜自习状态中选择“暂停住宿”对应状态，不能根据状态名称查找。

- 该状态必须为非默认状态。
- 建议 `base_status = excused`。
- 建议 `student_requestable = false`。
- 配置缺失或状态停用时，系统应阻止创建新的暂停记录并提示管理员完成配置。

### 8.3 学生申请行为

- 学生仍保持 `is_boarding = true`。
- 申请日期与暂停日期重叠时，不再显示或接受夜自习请假申请，因为该日期已不承担住宿生夜自习出勤要求。
- 普通白天请假不受暂停住宿影响。
- 班主任创建暂停记录时，如范围内已有待审批夜自习申请，应在同一事务中取消并记录“住宿许可暂停”。
- 已批准夜自习申请保留审批历史，但点名最终状态由暂停住宿优先覆盖。

### 8.4 值班点名行为

值班点名仍加载所有 `is_boarding = true` 的学生，包括暂停住宿学生。

- 学生行显示“暂停住宿”标识、起止日期、原因、去向和操作班主任。
- 创建点名记录时自动填入管理员配置的暂停住宿状态。
- `boarding_suspension_id` 关联生效的暂停记录。
- 暂停住宿学生计入点名名单总人数，但不计入默认正常人数。
- 值班老师可以根据现场情况手工修改最终状态，但暂停信息仍保留展示。

同步优先级中，暂停住宿高于已批准请假，低于值班老师手工确认。

## 9. 角色与权限

### 9.1 新增角色

- 数据值：`duty_teacher`
- 显示名称：系部值班老师

值班老师可以负责一个或多个系部。

建议新增关系表 `department_duty_teachers`：

| 字段 | 说明 |
| --- | --- |
| `id` | 主键 |
| `department_id` | 负责系部 |
| `user_id` | 值班老师 |
| `created_at` / `updated_at` | 时间戳 |

对 `department_id + user_id` 建立唯一约束。

### 9.2 权限项

- `evening_study.take`：执行夜自习点名。
- `evening_study.view`：查看夜自习记录。
- `evening_study.modify`：修改已完成点名。
- `evening_study.leave_approve`：审批夜自习请假。
- `evening_study.settings`：配置夜自习节次和状态。
- `boarding_suspensions.manage`：暂停或恢复住宿许可。

默认权限建议：

| 角色 | 点名 | 查看 | 修改 | 审批 | 暂停住宿 | 配置 |
| --- | --- | --- | --- | --- | --- | --- |
| 系统管理员 | 是 | 全校 | 是 | 是 | 是 | 是 |
| 校管理员 | 否 | 全校 | 是 | 是 | 是 | 否 |
| 系部管理员 | 否 | 本系部 | 是 | 是 | 本系部 | 否 |
| 系部值班老师 | 负责系部 | 负责系部 | 自己创建的场次 | 否 | 否 | 否 |
| 班主任 | 否 | 本班 | 本班 | 本班 | 本班 | 否 |
| 学生 | 否 | 仅本人记录 | 否 | 否 | 否 | 否 |

后端必须校验角色、负责系部和班级范围。菜单隐藏不能替代接口授权。

## 10. 学生申请流程

1. 学生进入现有请假申请页面。
2. 页面读取请假类型和节次配置。
3. 根据学生住宿状态过滤节次：
   - `audience_scope = all`：所有学生可见。
   - `audience_scope = boarding`：仅住宿生可见。
   - 申请日期处于暂停住宿许可范围：夜自习节次不可申请。
4. 时段只负责预选节次，学生可以在节次明细中增加或取消节次。
5. 选中的节次包含 `scene = evening_study` 后：
   - 保留已经选择的普通节次，不切换到独立夜自习模式。
   - 显示可申请的夜自习状态。
   - “具体去向”必填。
6. 后端通过所有 `period_id` 读取节次配置并验证：
   - 节次启用。
   - 适用范围允许当前学生。
   - 只要包含夜自习节次，学生必须是住宿生且住宿许可未暂停。
   - 夜自习状态启用且允许学生申请。
7. 每个节次创建一条 `attendance_records`，整批记录共享 `leave_batch_id`：
   - 普通节次复制 `scene = regular` 和对应的日常统计开关。
   - 夜自习节次复制 `scene = evening_study`、`counts_in_day_stats = false`、申请状态和具体去向。
   - 夜自习字段只写入夜自习记录，不污染普通节次记录。
8. 多日期申请继续使用现有 `leave_batch_id` 分组。
9. 学生在“我的记录”中看到夜自习标识、申请状态和具体去向。

## 11. 班主任流程

### 11.1 审批学生申请

现有审批页面增加“普通请假 / 夜自习请假”筛选，不创建第二套审批页面。

夜自习申请显示：

- 学生、班级和住宿状态。
- 日期、夜自习节次。
- 请假类型。
- 申请的夜自习状态。
- 具体去向和申请理由。
- 当前审批状态。

批准时：

- 继续更新现有 `approval_status / approver_id / approved_at`。
- 如果相关点名场次尚未完成且值班老师没有手工覆盖，将申请状态同步为最终状态。
- 如果点名已完成，只标记“点名后批准”，不静默修改历史点名结果。

驳回时：

- 保留申请状态和去向作为审批历史。
- 不自动写入最终夜自习状态。
- 如果值班老师已经完成点名，保留值班老师结果。

### 11.2 班主任直接标记

班主任从学生管理或考勤操作入口选择学生：

- 住宿生自动显示夜自习节次。
- 走读生不显示住宿生专用节次。
- 夜自习状态和具体去向按配置显示。
- 保存后直接生效，不需要再次审批。
- `is_self_applied = false`。
- `approval_status = null`。
- `evening_study_status_id` 直接保存最终状态。

若同一学生、日期、节次存在待审批申请，应提示班主任审批或使用教师结果替代，不能生成两条违反唯一约束的记录。

### 11.3 暂停和恢复住宿许可

班主任在学生管理中可以对住宿生执行“暂停住宿”：

- 开始日期和结束日期必填。
- 暂停原因必填，暂停期间去向可填写。
- 页面显示范围内已有的夜自习申请冲突。
- 保存后不改变 `is_boarding`，只创建 `boarding_suspensions`。
- 班主任可以提前恢复住宿许可，但不能删除历史记录。
- 学生列表显示当前暂停状态和截止日期，避免重复操作。

## 12. 值班老师点名流程

### 12.1 班级列表

值班老师登录后默认进入“夜自习点名”：

- 日期默认当天。
- 只显示负责系部中的班级。
- 只显示启用且 `scene = evening_study` 的节次。
- 班级显示住宿生人数、点名进度、正常人数和异常人数。

### 12.2 创建场次和记录

进入班级时创建或恢复“日期 + 班级 + 夜自习节次”对应的场次。

在一个数据库事务中：

1. 创建或锁定 `evening_study_sessions`。
2. 查询班级当前住宿生。
3. 查询这些学生当天该节次已有的 `attendance_records`。
4. 对没有记录的住宿生创建默认正常记录：
   - `scene = evening_study`
   - `counts_in_day_stats = false`
   - `status = present`
   - `evening_study_status_id = 默认状态`
   - `evening_study_session_id = 当前场次`
5. 对已有夜自习申请或班主任标记记录，关联当前场次，不重复创建。
6. 已批准申请自动将最终状态设置为申请状态。
7. 待审批申请保留申请内容，但最终状态暂设为默认正常，并在页面显示待审批提示。
8. 查询当天生效的暂停住宿许可：
   - 将最终状态设置为管理员配置的暂停住宿状态。
   - 写入 `boarding_suspension_id`。
   - 保留已有请假申请信息供值班老师查看。
   - 暂停住宿优先于申请预填，但不覆盖值班老师已有手工结果。

### 12.3 点名操作

- 学生行显示姓名、学号、最终状态和请假摘要。
- 已批准申请显示请假类型、申请状态和具体去向。
- 待审批申请显示醒目标识，但不自动算作批准请假。
- 暂停住宿学生显示独立标识、有效期、原因、去向和操作班主任。
- 点击状态后读取管理员启用的夜自习状态。
- 值班老师手工修改时更新：
  - `evening_study_status_id`
  - `status_name_snapshot`
  - 对应的标准 `status`
  - `manually_overridden_at`
  - `marked_by / marked_at`
- 手工修改不能删除 `requested_evening_status_id`、申请理由或审批历史。
- 完成点名时统一重算场次人数并提交事务。

### 12.4 同步优先级

1. 值班老师手工最终结果。
2. 当前生效的暂停住宿许可。
3. 班主任直接标记。
4. 已批准学生申请。
5. 系统默认正常。

已完成场次不会因之后的审批自动变化。后续审批仅显示“点名后批准”，由有权限人员决定是否修正。

## 13. 统计与查询改造

### 13.1 白天统计

所有白天统计查询必须使用统一作用域：

```php
AttendanceRecord::query()->where('counts_in_day_stats', true);
```

需要覆盖：

- 教师、系部和校级仪表盘。
- 学生个人统计。
- 旷课和请假折算。
- 日历摘要和详情。
- 考勤导出。
- 自动考勤处理。
- 普通点名与请假同步查询。

禁止在不同控制器中各自临时拼接过滤条件。应在 `AttendanceRecord` 增加统一查询作用域，例如：

- `scopeDayAttendance()`
- `scopeEveningStudy()`

### 13.2 夜自习统计

夜自习查询使用：

```php
AttendanceRecord::query()->where('scene', 'evening_study');
```

统计包括：

- 已点名班级数。
- 住宿生总数。
- 默认正常人数。
- 各自定义状态人数。
- 未完成点名班级。

夜自习状态统计按 `evening_study_status_id` 分组，不通过状态名称分组。

## 14. 通知策略

现有 `AttendanceRecordObserver` 会监听所有考勤记录。夜自习记录复用 `attendance_records` 后，必须先按 `scene` 分流，避免默认触发现有白天通知。

第一阶段：

- 夜自习学生申请不发送家长邮件或短信。
- 夜自习默认正常记录不发送通知。
- 夜自习异常状态不发送通知。
- 暂停或恢复住宿许可不发送通知。
- 现有普通请假和白天考勤通知保持不变。

后续可增加事件：

- `evening_study_leave_submitted`
- `evening_study_exception`

班主任可分别控制邮件和短信开关。夜自习通知使用独立去重键，避免点名修正时重复发送。

## 15. 接口规划

### 15.1 节次和状态配置

- 继续使用系统设置接口保存 `attendance_periods`。
- 增加后端节次配置验证，不能只接受任意 JSON。
- `GET /api/evening-study/statuses`
- `POST /api/evening-study/statuses`
- `PUT /api/evening-study/statuses/{status}`
- `DELETE /api/evening-study/statuses/{status}`，有历史引用时拒绝删除或改为停用

### 15.2 统一请假

继续使用现有请假接口：

- `GET /api/leave-requests`
- `POST /api/leave-requests`
- `POST /api/leave-requests/{id}/approve`
- `POST /api/leave-requests/{id}/reject`
- `DELETE /api/leave-requests/{id}`

后端根据提交的 `period_id` 或 `sessions` 读取节次场景并执行普通或夜自习校验。

列表接口增加 `scene` 筛选，并在响应中返回夜自习状态和具体去向。

### 15.3 夜自习点名

- `GET /api/evening-study/classes?date=&period_id=`
- `POST /api/evening-study/sessions`
- `GET /api/evening-study/sessions/{session}`
- `PUT /api/evening-study/sessions/{session}/records`
- `POST /api/evening-study/sessions/{session}/complete`
- `POST /api/evening-study/sessions/{session}/reopen`
- `GET /api/evening-study/history`

### 15.4 暂停住宿许可

- `GET /api/boarding-suspensions?student_id=&date=`
- `POST /api/boarding-suspensions`
- `POST /api/boarding-suspensions/{suspension}/revoke`

不提供物理删除接口。创建和提前恢复都必须记录操作人，并验证班级管辖范围。

批量保存时必须验证：

- 学生属于场次班级。
- 学生是场次创建时的住宿生。
- 状态属于当前学校且已启用。
- 操作人具有当前系部和班级权限。
- 记录的日期、节次和场次一致。

## 16. 前端规划

### 16.1 系统设置

“考勤规则 -> 节次管理”增加：

- 适用学生：全体学生 / 仅住宿生。
- 业务场景：普通考勤 / 夜自习。
- 计入白天考勤统计开关。
- 启用状态。

合理的联动默认值：

- 选择夜自习时，默认适用住宿生且不计入白天统计。
- 管理员仍可明确查看最终保存值。

系统设置增加“夜自习状态”栏目：

- 状态名称和颜色。
- 基础考勤状态。
- 默认状态。
- 学生是否可以申请。
- 关联请假类型。
- 排序、启用和停用。
- “暂停住宿默认状态”选择器，保存 `boarding_suspension_status_id`。

### 16.2 人员管理

- 增加“系部值班老师”页签。
- 支持创建、编辑、重置密码和分配多个系部。
- 值班老师不绑定具体班级。

### 16.3 学生端

- 保留现有统一请假页面。
- 根据住宿状态和节次适用范围自动过滤选项。
- 选择夜自习后显示夜自习状态和必填去向。
- 当前或所选日期处于暂停住宿许可时，显示暂停期限并禁止申请夜自习。
- 我的记录增加普通考勤和夜自习标识/筛选。

### 16.4 班主任端

- 审批记录增加场景筛选。
- 学生管理或考勤操作支持直接标记夜自习。
- 选择夜自习时只允许住宿生。
- 学生管理增加暂停住宿、提前恢复和历史查看。
- 当前暂停学生显示状态、截止日期和原因。
- 查看本班夜自习历史和异常名单。

### 16.5 值班老师端

- 登录后默认进入夜自习点名页面。
- 按日期、夜自习节次和班级操作。
- 页面面向手机快速点名设计。
- 所有住宿生默认正常。
- 请假类型、申请状态和具体去向直接显示在学生行内。
- 暂停住宿学生仍在名单中，并显示暂停期限、原因、去向和操作班主任。

## 17. 数据迁移与部署

### 17.1 用户角色枚举

当前 MySQL `users.role` 为枚举。新增 `duty_teacher` 时必须通过迁移保留所有已有角色值和用户数据。

### 17.2 考勤记录迁移

为已有 `attendance_records` 设置：

```text
scene = regular
counts_in_day_stats = true
```

新增字段应允许旧代码部署过程中的短暂兼容，并为常用查询建立索引：

- `scene + date`
- `counts_in_day_stats + date`
- `evening_study_session_id`
- `evening_study_status_id + date`

### 17.3 节次配置迁移

对现有 `attendance_periods` JSON 补齐默认字段，不改变现有节次 ID。

创建 `boarding_suspensions` 并为 `attendance_records.boarding_suspension_id` 建立外键和索引。暂停记录不需要从现有 `is_boarding` 数据回填。

### 17.4 生产迁移失败

当前 `docker-entrypoint.sh` 使用：

```bash
php artisan migrate --force || true
```

该写法会忽略迁移失败。应改为迁移失败即终止部署，避免 Dokploy 启动数据库结构不完整的版本。

### 17.5 住宿生基础数据

本地当前学生尚未标记住宿生。验收前需要通过学生管理或 Excel 导入维护住宿状态。

## 18. 测试范围

### 18.1 节次和资格

- 旧节次配置自动使用兼容默认值。
- 走读生不能看到或提交住宿生专用节次。
- 住宿生可以申请启用的夜自习节次。
- 暂停住宿期间不能提交夜自习申请，但仍可提交普通白天请假。
- 停用节次不能用于新申请或新点名。
- 后端拒绝伪造的场景、范围和统计开关。

### 18.2 请假和审批

- 普通请假行为保持不变。
- 夜自习申请写入 `attendance_records` 且不计入白天统计。
- 夜自习具体去向必填。
- 学生只能选择允许申请且关联类型正确的状态。
- 多日期夜自习申请正确使用批次。
- 同学生、同日期、同节次不能产生冲突记录。
- 班主任只能审批和标记自己班级的住宿生。
- 待审批、已批准、已驳回和教师标记正确展示。
- 创建暂停住宿时正确处理范围内待审批夜自习申请。

### 18.3 点名

- 值班老师只能访问负责系部。
- 场次只初始化住宿生。
- 暂停住宿学生仍进入名单并自动使用配置状态。
- 暂停信息、原因、期限和操作人正确显示。
- 无申请学生默认正常。
- 已批准申请自动预填。
- 待审批申请只提示，最终状态默认正常。
- 手工点名不删除申请内容。
- 手工结果不会被后续同步覆盖。
- 值班老师手工结果可覆盖最终点名状态，但不删除暂停历史。
- 同班、同日期、同节次不能重复创建场次。
- 完成点名失败时场次和考勤记录全部回滚。

### 18.4 统计、通知和回归

- 所有白天统计排除 `counts_in_day_stats = false`。
- 夜自习记录不会影响白天折算和 Excel 导出。
- 夜自习默认不触发现有邮件和短信。
- 普通请假和普通考勤通知不受影响。
- 状态、节次改名不改变历史快照。

### 18.5 浏览器和部署

- 学生端走读生与住宿生显示差异正确。
- 手机端请假表单和点名页面无横向溢出。
- OrbStack `classstudentsmanange` 容器迁移和测试通过。
- 前端生产构建通过。
- Dokploy 新数据库和已有数据库均可迁移。
- 迁移失败时部署正确失败。

## 19. TODO

### 阶段一：配置和数据库

- [x] 扩展 `attendance_periods` 配置结构。
- [x] 为旧节次补齐兼容默认值。
- [x] 增加节次配置后端校验和读取服务。
- [x] 创建 `evening_study_statuses`。
- [x] 创建 `evening_study_sessions`。
- [x] 创建 `boarding_suspensions`。
- [x] 扩展 `attendance_records` 夜自习字段。
- [x] 为已有考勤记录回填普通场景和白天统计开关。
- [x] 添加模型关联、查询作用域和数据库索引。

### 阶段二：角色与权限

- [x] 为 `users.role` 增加 `duty_teacher`。
- [x] 创建 `department_duty_teachers`。
- [x] 增加夜自习权限并通过迁移写入生产数据库。
- [x] 更新用户模型、权限矩阵和人员管理接口。
- [x] 更新登录跳转和菜单。
- [x] 确保所有夜自习接口执行后端作用域校验。

### 阶段三：系统设置

- [x] 节次管理增加适用学生配置。
- [x] 节次管理增加普通考勤/夜自习场景配置。
- [x] 节次管理增加白天统计开关和启用状态。
- [x] 实现有历史引用节次的停用保护。
- [x] 新增夜自习状态管理栏目。
- [x] 实现唯一默认状态和历史引用保护。
- [x] 实现夜自习状态关联现有请假类型。
- [x] 增加“暂停住宿默认状态”配置并校验引用状态。

### 阶段四：统一请假入口

- [x] 按学生住宿状态过滤节次。
- [x] 选择夜自习后动态显示状态和具体去向。
- [x] 后端根据真实节次配置决定记录场景。
- [x] 复用现有批次、冲突和审批字段。
- [x] 扩展请假列表响应和场景筛选。
- [x] 学生历史展示夜自习记录。
- [x] 暂停住宿日期范围内禁止学生提交夜自习申请。

### 阶段五：班主任审批和代标记

- [x] 审批页面增加普通/夜自习筛选。
- [x] 扩展批准、驳回和取消逻辑。
- [x] 处理批准时间与点名完成时间的关系。
- [x] 实现班主任直接标记夜自习。
- [x] 处理直接标记与待审批记录冲突。
- [x] 限制班主任只能操作本班住宿生。
- [ ] 学生管理增加暂停住宿、提前恢复和历史记录。
- [x] 实现暂停日期重叠校验和夜自习申请冲突处理。

### 阶段六：值班老师点名

- [x] 实现夜自习班级列表。
- [x] 按负责系部限制班级。
- [x] 创建或恢复日期、班级、节次对应的场次。
- [x] 为住宿生创建或关联统一考勤记录。
- [x] 同步已批准申请并显示待审批信息。
- [x] 点名时同步暂停住宿状态和详细信息。
- [x] 实现逐人状态切换和批量事务保存。
- [x] 实现场次完成、恢复、历史和修正。
- [x] 实现班主任和管理人员历史视图。

### 阶段七：统计、通知和回归

- [x] 在 `AttendanceRecord` 增加白天和夜自习查询作用域。
- [x] 改造所有白天统计和导出查询。
- [x] 改造自动考勤和普通点名相关查询。
- [x] 修改通知观察器，按场景分流。
- [x] 增加普通请假和普通考勤回归测试。
- [x] 增加夜自习领域、权限和事务测试。

### 阶段八：本地验证和部署

- [x] 在 OrbStack 容器运行迁移。
- [ ] 准备住宿生、走读生、班主任和值班老师测试账号。
- [x] 运行完整 PHP 测试和前端构建。
- [x] 使用 Chrome 验证桌面端和手机端。
- [x] 修正部署脚本，不再忽略迁移失败。
- [ ] 验证 Dokploy 已有数据库升级路径。
- [ ] 整理部署和回滚说明。

### 后续可选功能

- [ ] 夜自习异常邮件事件。
- [ ] 夜自习异常短信事件。
- [ ] 夜自习统计和 Excel 导出。
- [ ] 值班排班表。
- [ ] 漏点提醒和点名截止时间。
- [ ] 按系部限制可选夜自习状态。

## 20. 当前默认决策

在没有新的业务调整前，实施时采用以下规则：

1. 学生只有一个请假入口。
2. 只有住宿生显示和使用夜自习节次。
3. 夜自习节次在考勤规则中配置，可加入“全天”等现有时段预设，但不创建独立夜自习时段。
4. 夜自习请假和点名继续使用 `attendance_records`。
5. 夜自习记录默认不计入白天考勤统计。
6. 统计开关保存到每条考勤记录，保证历史稳定。
7. 学生申请必须由班主任审批。
8. 班主任代为标记直接生效。
9. 已批准申请自动预填点名状态。
10. 待审批申请只提示，最终状态默认正常。
11. 值班老师手工结果优先于自动同步。
12. 已完成点名不会因之后的审批自动改变。
13. 暂停住宿不改变学生的长期住宿生身份。
14. 暂停住宿学生仍出现在值班点名名单中。
15. 暂停住宿优先于请假预填，值班老师手工结果优先于暂停状态。
16. 暂停期间学生不能申请夜自习请假，但普通白天请假不受影响。
17. 第一阶段不发送夜自习或暂停住宿邮件、短信通知。
18. 普通节次和夜自习节次允许在一次申请中混选，并按同一批次审批。
