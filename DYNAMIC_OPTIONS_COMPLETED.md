# 动态时长选项管理功能完成报告

## ✅ 功能已完成：自定义时长选择选项

### 需求
管理员希望能够自定义"时长选择"类型的选项，而不是硬编码为"上午、下午、全天"。这样可以为不同的请假类型配置不同的时长选项。

**示例场景**：
- **病假**：上午、下午、全天
- **生理假**：早操、晚操
- **其他假**：1节课、2节课、半天、全天

---

## 🔧 实现内容

### 1. 系统设置页面 - 动态选项管理

**文件**：`resources/js/pages/admin/SettingsPage.jsx`

#### 修改1：添加状态管理（第134-177行）
```javascript
const [durationOptions, setDurationOptions] = useState([]);

useEffect(() => {
    if (initialData) {
        const cfg = initialData.input_config || {};
        
        // 初始化 duration 选项
        if (cfg.options && Array.isArray(cfg.options)) {
            // 检查是新格式（对象数组）还是旧格式（字符串数组）
            if (cfg.options.length > 0 && typeof cfg.options[0] === 'object') {
                // 新格式：[{key, label}, ...]
                setDurationOptions(cfg.options);
            } else {
                // 旧格式：['morning_half', ...]
                // 转换为 {key, label} 格式
                const optionMap = {
                    'morning_half': '上午',
                    'afternoon_half': '下午',
                    'full_day': '全天',
                    // ...
                };
                const opts = cfg.options.map(key => ({
                    key: key,
                    label: optionMap[key] || key
                }));
                setDurationOptions(opts);
            }
        } else {
            // 默认选项
            setDurationOptions([
                { key: 'morning_half', label: '上午' },
                { key: 'afternoon_half', label: '下午' },
                { key: 'full_day', label: '全天' }
            ]);
        }
    }
}, [initialData]);
```

#### 修改2：添加管理函数（第179-195行）
```javascript
// 添加选项
const addDurationOption = () => {
    setDurationOptions([...durationOptions, { key: '', label: '' }]);
};

// 删除选项
const removeDurationOption = (index) => {
    setDurationOptions(durationOptions.filter((_, i) => i !== index));
};

// 更新选项
const updateDurationOption = (index, field, value) => {
    const newOptions = [...durationOptions];
    newOptions[index][field] = value;
    setDurationOptions(newOptions);
};
```

#### 修改3：更新保存逻辑（第210-213行）
```javascript
} else if (inputType === 'duration_select') {
    // 使用动态选项，保存完整的 {key, label} 对象
    const options = durationOptions.filter(opt => opt.key && opt.label);
    finalConfig = { options };
}
```

#### 修改4：动态UI（第297-340行）
```javascript
{inputType === 'duration_select' && (
    <div>
        <div className="flex justify-between items-center mb-2">
            <label className="text-xs text-gray-500">选项配置</label>
            <button 
                type="button" 
                onClick={addDurationOption}
                className="text-xs bg-indigo-500 text-white px-2 py-1 rounded hover:bg-indigo-600"
            >
                + 添加选项
            </button>
        </div>
        <div className="space-y-2">
            {durationOptions.map((opt, index) => (
                <div key={index} className="flex gap-2 items-center">
                    <input
                        type="text"
                        placeholder="键值 (如: morning_half)"
                        value={opt.key}
                        onChange={(e) => updateDurationOption(index, 'key', e.target.value)}
                        className="flex-1 text-xs border rounded px-2 py-1"
                    />
                    <input
                        type="text"
                        placeholder="显示文本 (如: 上午)"
                        value={opt.label}
                        onChange={(e) => updateDurationOption(index, 'label', e.target.value)}
                        className="flex-1 text-xs border rounded px-2 py-1"
                    />
                    <button
                        type="button"
                        onClick={() => removeDurationOption(index)}
                        className="text-red-500 hover:text-red-700 text-sm"
                    >
                        ✕
                    </button>
                </div>
            ))}
            {durationOptions.length === 0 && (
                <p className="text-xs text-gray-400">暂无选项，点击"添加选项"开始配置</p>
            )}
        </div>
    </div>
)}
```

---

### 2. 考勤标记模态框 - 显示自定义选项

**文件**：`resources/js/components/AttendanceUpdateModal.jsx`

#### 修改：兼容新旧格式（第642-667行）
```javascript
return options.map(opt => {
    // 兼容新旧格式
    const optKey = typeof opt === 'object' ? opt.key : opt;
    const optLabel = typeof opt === 'object' ? opt.label : (
        opt === 'morning_half' ? '上午' :
        (opt === 'afternoon_half' ? '下午' :
        (opt === 'full_day' ? '全天' :
        (opt === 'morning_exercise' ? '早操' :
        (opt === 'evening_exercise' ? '晚操' : opt))))
    );
    
    return (
        <label key={optKey} className="flex items-center">
            <input
                type="radio"
                name="duration_opt"
                className="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500"
                onChange={() => setInputData({ ...inputData, option: optKey })}
            />
            <span className="ml-2 text-sm text-gray-700">
                {optLabel}
            </span>
        </label>
    );
})
```

---

## 📊 数据格式

### 旧格式（向后兼容）
```json
{
  "input_type": "duration_select",
  "input_config": {
    "options": ["morning_half", "afternoon_half", "full_day"]
  }
}
```

### 新格式
```json
{
  "input_type": "duration_select",
  "input_config": {
    "options": [
      {"key": "morning_half", "label": "上午"},
      {"key": "afternoon_half", "label": "下午"},
      {"key": "full_day", "label": "全天"}
    ]
  }
}
```

---

## 🎯 使用方法

### 1. 编辑请假类型
1. 进入"系统设置" → "请假类型"
2. 点击"编辑"按钮（如编辑"病假"）
3. 选择"输入类型"为"时长选择 (Duration)"

### 2. 配置选项
1. 点击"+ 添加选项"按钮
2. 输入"键值"（如 `morning_half`、`evening_exercise`、`1_period`）
3. 输入"显示文本"（如 `上午`、`晚操`、`1节课`）
4. 可以添加多个选项
5. 点击 ✕ 删除不需要的选项

### 3. 保存
点击"保存"按钮，配置即生效。

### 4. 使用
在考勤标记时，点击该请假类型，会显示您配置的自定义选项。

---

## ✨ 功能特点

### 1. 完全自定义
- ✅ 自由添加选项
- ✅ 自定义键值和显示文本
- ✅ 删除不需要的选项
- ✅ 每个请假类型可以有不同的选项

### 2. 向后兼容
- ✅ 自动识别旧格式数据
- ✅ 自动转换为新格式显示
- ✅ 不影响现有数据

### 3. 灵活配置
- ✅ 病假：上午、下午、全天
- ✅ 生理假：早操、晚操
- ✅ 其他假：1节课、2节课、半天、全天
- ✅ 任意组合

---

## 🧪 测试步骤

### 测试1：添加自定义选项
1. 进入"系统设置" → "请假类型"
2. 编辑"病假"
3. 选择"时长选择 (Duration)"
4. 点击"+ 添加选项"
5. 输入键值：`morning_half`，显示文本：`上午`
6. 再添加：`afternoon_half` / `下午`
7. 再添加：`full_day` / `全天`
8. 保存

### 测试2：使用自定义选项
1. 进入考勤标记
2. 选择学生
3. 点击"病假"
4. ✅ 应该看到：上午、下午、全天 三个选项
5. 选择"上午"
6. 点击"确定"
7. ✅ 应该显示：`[病假(上午)]`

### 测试3：不同请假类型不同选项
1. 编辑"生理假"
2. 配置选项：`morning_exercise` / `早操`，`evening_exercise` / `晚操`
3. 保存
4. 在考勤标记中点击"生理假"
5. ✅ 应该看到：早操、晚操 两个选项

---

## 📝 修改的文件

1. ✅ `resources/js/pages/admin/SettingsPage.jsx`
   - 第134-177行：状态管理和初始化
   - 第179-195行：管理函数
   - 第210-213行：保存逻辑
   - 第297-340行：动态UI

2. ✅ `resources/js/components/AttendanceUpdateModal.jsx`
   - 第642-667行：显示逻辑（兼容新旧格式）

---

## 💡 技术要点

### 数据结构
- **键值（key）**：用于后端存储和逻辑处理
- **显示文本（label）**：用于前端显示

### 兼容性
- 自动检测数据格式（`typeof opt === 'object'`）
- 旧格式自动转换为新格式
- 保证现有数据不受影响

### 扩展性
- 可以轻松添加新的选项类型
- 不需要修改代码，只需在UI中配置
- 支持任意数量的选项

---

*完成时间: 2025-12-19 08:02*
*功能: 动态时长选项管理*
*状态: ✅ 已完成*
