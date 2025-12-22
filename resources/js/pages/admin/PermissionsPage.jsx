import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { CheckIcon, XMarkIcon } from '@heroicons/react/24/outline';

export default function PermissionsPage() {
    const [matrix, setMatrix] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [changes, setChanges] = useState({});

    const roleLabels = {
        'system_admin': '系统管理员',
        'school_admin': '校管理员',
        'department_manager': '系部管理员',
        'teacher': '班主任',
        'student': '学生'
    };

    const actionLabels = {
        'can_create': '创建',
        'can_read': '查看',
        'can_update': '编辑',
        'can_delete': '删除'
    };

    const categoryLabels = {
        'system': '系统设置',
        'user': '用户管理',
        'attendance': '考勤管理',
        'leave': '请假管理',
        'student': '学生管理',
        'class': '班级管理'
    };

    useEffect(() => {
        fetchMatrix();
    }, []);

    const fetchMatrix = async () => {
        try {
            const res = await axios.get('/permissions/matrix');
            setMatrix(res.data.matrix);
            setRoles(res.data.roles);
        } catch (error) {
            console.error('Failed to fetch permissions', error);
        } finally {
            setLoading(false);
        }
    };

    const togglePermission = (permissionId, role, action) => {
        const key = `${permissionId}-${role}-${action}`;
        const permission = matrix.find(p => p.id === permissionId);
        const currentValue = permission.roles[role][action];

        setChanges(prev => ({
            ...prev,
            [key]: {
                permission_id: permissionId,
                role,
                [action]: !currentValue,
                // Keep other actions unchanged
                ...Object.keys(actionLabels).reduce((acc, act) => {
                    if (act !== action) {
                        acc[act] = permission.roles[role][act];
                    }
                    return acc;
                }, {})
            }
        }));

        // Update matrix locally
        setMatrix(prev => prev.map(p => {
            if (p.id === permissionId) {
                return {
                    ...p,
                    roles: {
                        ...p.roles,
                        [role]: {
                            ...p.roles[role],
                            [action]: !currentValue
                        }
                    }
                };
            }
            return p;
        }));
    };

    const saveChanges = async () => {
        if (Object.keys(changes).length === 0) {
            alert('没有需要保存的更改');
            return;
        }

        setSaving(true);
        try {
            await axios.post('/permissions/batch-update', {
                updates: Object.values(changes)
            });
            setChanges({});
            alert('权限配置已保存');
        } catch (error) {
            alert('保存失败: ' + (error.response?.data?.message || '未知错误'));
        } finally {
            setSaving(false);
        }
    };

    const resetChanges = () => {
        setChanges({});
        fetchMatrix();
    };

    // Group permissions by category
    const groupedPermissions = matrix.reduce((acc, perm) => {
        const category = perm.category || 'other';
        if (!acc[category]) acc[category] = [];
        acc[category].push(perm);
        return acc;
    }, {});

    if (loading) {
        return (
            <Layout>
                <div className="flex justify-center items-center h-64">
                    <div className="text-gray-500">加载中...</div>
                </div>
            </Layout>
        );
    }

    return (
        <Layout>
            <div className="md:flex md:items-center md:justify-between mb-6">
                <div className="min-w-0 flex-1">
                    <h2 className="text-2xl font-bold leading-7 text-gray-900 sm:truncate sm:text-3xl sm:tracking-tight">
                        权限管理
                    </h2>
                    <p className="mt-1 text-sm text-gray-500">
                        配置不同角色的系统权限（仅系统管理员可见）
                    </p>
                </div>
                <div className="mt-4 flex md:ml-4 md:mt-0 space-x-3">
                    {Object.keys(changes).length > 0 && (
                        <>
                            <button
                                onClick={resetChanges}
                                className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                取消 ({Object.keys(changes).length})
                            </button>
                            <button
                                onClick={saveChanges}
                                disabled={saving}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                            >
                                {saving ? '保存中...' : `保存更改 (${Object.keys(changes).length})`}
                            </button>
                        </>
                    )}
                </div>
            </div>

            {/* Permission Matrix */}
            <div className="space-y-8">
                {Object.entries(groupedPermissions).map(([category, permissions]) => (
                    <div key={category} className="bg-white shadow-sm ring-1 ring-gray-900/5 sm:rounded-xl">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                {categoryLabels[category] || category}
                            </h3>

                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-300">
                                    <thead>
                                        <tr>
                                            <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 sm:pl-0">
                                                权限
                                            </th>
                                            {roles.map(role => (
                                                <th key={role} className="px-3 py-3.5 text-center text-sm font-semibold text-gray-900" colSpan="4">
                                                    {roleLabels[role]}
                                                </th>
                                            ))}
                                        </tr>
                                        <tr className="bg-gray-50">
                                            <th className="py-2 pl-4 pr-3 text-left text-xs font-medium text-gray-500 sm:pl-0"></th>
                                            {roles.map(role => (
                                                <React.Fragment key={role}>
                                                    {Object.keys(actionLabels).map(action => (
                                                        <th key={`${role}-${action}`} className="px-1 py-2 text-center text-xs font-medium text-gray-500">
                                                            {actionLabels[action]}
                                                        </th>
                                                    ))}
                                                </React.Fragment>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {permissions.map(permission => (
                                            <tr key={permission.id} className="hover:bg-gray-50">
                                                <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm font-medium text-gray-900 sm:pl-0">
                                                    {permission.display_name}
                                                    <div className="text-xs text-gray-500">{permission.name}</div>
                                                </td>
                                                {roles.map(role => (
                                                    <React.Fragment key={role}>
                                                        {Object.keys(actionLabels).map(action => {
                                                            const isEnabled = permission.roles[role][action];
                                                            const isSystemAdmin = role === 'system_admin';

                                                            return (
                                                                <td key={`${role}-${action}`} className="px-1 py-4 text-center">
                                                                    <button
                                                                        onClick={() => !isSystemAdmin && togglePermission(permission.id, role, action)}
                                                                        disabled={isSystemAdmin}
                                                                        className={`inline-flex items-center justify-center w-8 h-8 rounded ${isSystemAdmin
                                                                                ? 'bg-gray-200 cursor-not-allowed'
                                                                                : isEnabled
                                                                                    ? 'bg-green-100 hover:bg-green-200 text-green-700'
                                                                                    : 'bg-gray-100 hover:bg-gray-200 text-gray-400'
                                                                            }`}
                                                                        title={isSystemAdmin ? '系统管理员拥有所有权限' : (isEnabled ? '点击禁用' : '点击启用')}
                                                                    >
                                                                        {isEnabled ? (
                                                                            <CheckIcon className="h-5 w-5" />
                                                                        ) : (
                                                                            <XMarkIcon className="h-5 w-5" />
                                                                        )}
                                                                    </button>
                                                                </td>
                                                            );
                                                        })}
                                                    </React.Fragment>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            {/* Legend */}
            <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
                <h4 className="text-sm font-semibold text-blue-900 mb-2">说明</h4>
                <ul className="text-sm text-blue-800 space-y-1">
                    <li>• 系统管理员默认拥有所有权限，无法修改</li>
                    <li>• 点击图标可以切换权限的启用/禁用状态</li>
                    <li>• 修改后需要点击"保存更改"按钮才会生效</li>
                    <li>• 学生管理员的权限通过is_manager标志控制，不在此配置</li>
                </ul>
            </div>
        </Layout>
    );
}
