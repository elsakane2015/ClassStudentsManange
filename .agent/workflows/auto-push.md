---
description: 修复完成后自动推送到 GitHub
---

# 代码修复后自动推送工作流

当完成代码修复或功能开发后，自动执行以下步骤推送到 GitHub：

## 步骤

// turbo-all

1. 检查当前 git 状态
```bash
cd /Users/xue/Documents/vscode/ClassStudentsManange
git status --short
```

2. 添加所有修改的文件
```bash
git add -A
```

3. 提交更改（使用描述性的提交信息）
```bash
git commit -m "fix: 修复问题描述"
```

4. 推送到远程仓库
```bash
git push
```

## 提交信息规范

- `fix:` 修复 bug
- `feat:` 新功能
- `refactor:` 代码重构
- `docs:` 文档更新
- `style:` 样式调整
- `perf:` 性能优化

## 注意事项

- 推送前确保代码已测试
- 如有冲突需要手动解决
- 敏感信息不要提交
