# 动态时长选项管理功能实现指南

## 需要修改的文件
`resources/js/pages/admin/SettingsPage.jsx`

## 修改位置：第297-305行

### 替换前的代码：
```javascript
                        {inputType === 'duration_select' && (
                            <div>
                                <label className="text-xs text-gray-500 mb-1 block">选项 (多选)</label>
                                <div className="space-y-1">
                                    <label className="flex items-center text-sm"><input name="config_opt_morning_half" type="checkbox" defaultChecked={config.options?.includes('morning_half')} className="mr-2" /> 上午</label>
                                    <label className="flex items-center text-sm"><input name="config_opt_afternoon_half" type="checkbox" defaultChecked={config.options?.includes('afternoon_half')} className="mr-2" /> 下午</label>
                                    <label className="flex items-center text-sm"><input name="config_opt_full" type="checkbox" defaultChecked={config.options?.includes('full_day')} className="mr-2" /> 全天</label>
                                </div>
                            </div>
                        )}
```

### 替换后的代码：
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

## 已完成的修改

1. ✅ 添加了 `durationOptions` 状态管理
2. ✅ 添加了 `addDurationOption`、`removeDurationOption`、`updateDurationOption` 函数
3. ✅ 更新了保存逻辑，使用 `durationOptions` 而不是硬编码的复选框
4. ⏳ 需要手动替换UI部分（第297-305行）

## 手动操作步骤

1. 打开文件：`resources/js/pages/admin/SettingsPage.jsx`
2. 找到第297行开始的 `{inputType === 'duration_select' && (`
3. 删除第297-305行的内容
4. 粘贴上面"替换后的代码"
5. 保存文件
6. 运行 `npm run build`

## 功能说明

修改后，管理员可以：
- 点击"+ 添加选项"按钮添加新选项
- 输入"键值"（如 `morning_half`、`evening_exercise`）
- 输入"显示文本"（如 `上午`、`晚操`）
- 点击 ✕ 删除不需要的选项

这样不同的请假类型可以配置不同的时长选项。

