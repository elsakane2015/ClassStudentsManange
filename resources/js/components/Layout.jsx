import React, { Fragment } from 'react';
import { Disclosure, Menu, Transition } from '@headlessui/react';
import { Bars3Icon, XMarkIcon, BellIcon } from '@heroicons/react/24/outline';
import { useLocation, Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../store/authStore';
import { authService } from '../services/auth';
import clsx from 'clsx';

function classNames(...classes) {
    return classes.filter(Boolean).join(' ');
}

export default function Layout({ children }) {
    const { user, logout } = useAuthStore();
    const location = useLocation();
    const navigate = useNavigate();

    const handleLogout = async () => {
        try {
            await authService.logout();
        } catch (error) {
            console.error("Logout API failed", error);
        } finally {
            logout();
            navigate('/login');
        }
    };

    const navigation = user?.role === 'student'
        ? [
            { name: '仪表盘', href: '/student/dashboard' },
            { name: '请假申请', href: '/student/request' },
            { name: '我的记录', href: '/student/history' },
        ]
        : [
            { name: '概览', href: '/teacher/dashboard' },
            { name: '请假审批', href: '/teacher/approvals' },
            { name: '学生管理', href: '/teacher/students' },
            ...(['system_admin', 'school_admin', 'department_manager', 'admin', 'manager'].includes(user?.role) ? [{ name: '人员管理', href: '/admin/staff' }] : []),
            ...(['system_admin', 'school_admin', 'admin'].includes(user?.role) ? [{ name: '系统设置', href: '/admin/settings' }] : []),
            ...(['system_admin'].includes(user?.role) ? [{ name: '权限管理', href: '/admin/permissions' }] : [])
        ];

    return (
        <div className="min-h-full">
            <Disclosure as="nav" className="bg-indigo-600">
                {({ open }) => (
                    <>
                        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                            <div className="flex h-16 items-center justify-between">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <span className="text-white font-bold text-xl">🛡️ 智慧校园</span>
                                    </div>
                                    <div className="hidden md:block">
                                        <div className="ml-10 flex items-baseline space-x-4">
                                            {navigation.map((item) => (
                                                <Link
                                                    key={item.name}
                                                    to={item.href}
                                                    className={classNames(
                                                        location.pathname === item.href
                                                            ? 'bg-indigo-700 text-white'
                                                            : 'text-white hover:bg-indigo-500 hover:bg-opacity-75',
                                                        'rounded-md px-3 py-2 text-sm font-medium'
                                                    )}
                                                >
                                                    {item.name}
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                                <div className="hidden md:block">
                                    <div className="ml-4 flex items-center md:ml-6">
                                        <span className="text-indigo-200 text-sm mr-4">你好, {user?.name}</span>
                                        <button
                                            onClick={handleLogout}
                                            className="text-white text-sm hover:text-indigo-200"
                                        >
                                            退出登录
                                        </button>
                                    </div>
                                </div>
                                <div className="-mr-2 flex md:hidden">
                                    {/* Mobile menu button */}
                                    <Disclosure.Button className="relative inline-flex items-center justify-center rounded-md bg-indigo-600 p-2 text-indigo-200 hover:bg-indigo-500 hover:bg-opacity-75 hover:text-white focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-indigo-600">
                                        <span className="absolute -inset-0.5" />
                                        <span className="sr-only">Open main menu</span>
                                        {open ? (
                                            <XMarkIcon className="block h-6 w-6" aria-hidden="true" />
                                        ) : (
                                            <Bars3Icon className="block h-6 w-6" aria-hidden="true" />
                                        )}
                                    </Disclosure.Button>
                                </div>
                            </div>
                        </div>

                        <Disclosure.Panel className="md:hidden">
                            <div className="space-y-1 px-2 pb-3 pt-2 sm:px-3">
                                {navigation.map((item) => (
                                    <Link
                                        key={item.name}
                                        to={item.href}
                                        className={classNames(
                                            location.pathname === item.href
                                                ? 'bg-indigo-700 text-white'
                                                : 'text-white hover:bg-indigo-500 hover:bg-opacity-75',
                                            'block rounded-md px-3 py-2 text-base font-medium'
                                        )}
                                    >
                                        {item.name}
                                    </Link>
                                ))}
                                <button
                                    onClick={handleLogout}
                                    className="block w-full text-left rounded-md px-3 py-2 text-base font-medium text-white hover:bg-indigo-500 hover:bg-opacity-75"
                                >
                                    退出登录
                                </button>
                            </div>
                        </Disclosure.Panel>
                    </>
                )}
            </Disclosure>

            <header className="bg-white shadow-sm">
                <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
                    <h1 className="text-lg font-semibold leading-6 text-gray-900">
                        {navigation.find(n => n.href === location.pathname)?.name || 'Dashboard'}
                    </h1>
                </div>
            </header>
            <main>
                <div className="mx-auto max-w-7xl py-6 sm:px-6 lg:px-8">
                    {children}
                </div>
            </main>
        </div>
    );
}
