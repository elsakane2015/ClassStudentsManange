import React, { Fragment, useState, useEffect } from 'react';
import { Disclosure, Menu, Transition } from '@headlessui/react';
import { Bars3Icon, XMarkIcon, BellIcon, KeyIcon, ArrowRightOnRectangleIcon, ChevronDownIcon } from '@heroicons/react/24/outline';
import { useLocation, Link, useNavigate } from 'react-router-dom';
import useAuthStore from '../store/authStore';
import { authService } from '../services/auth';
import clsx from 'clsx';
import axios from 'axios';
import ChangePasswordModal from './ChangePasswordModal';

function classNames(...classes) {
    return classes.filter(Boolean).join(' ');
}

export default function Layout({ children }) {
    const { user, logout, hasPermission } = useAuthStore();
    const location = useLocation();
    const navigate = useNavigate();

    // WeChat menu visibility state
    const [showWechatMenu, setShowWechatMenu] = useState(false);
    // Change password modal state
    const [showPasswordModal, setShowPasswordModal] = useState(false);
    // School name state
    const [schoolName, setSchoolName] = useState('智慧校园');

    useEffect(() => {
        // Fetch school info
        axios.get('/school/info')
            .then(res => {
                const name = res.data?.name || '智慧校园';
                setSchoolName(name);
                document.title = name + ' - 考勤系统';
            })
            .catch(() => {
                setSchoolName('智慧校园');
            });

        // Listen for school name updates
        const handleSchoolNameUpdate = (e) => {
            const name = e.detail?.name || '智慧校园';
            setSchoolName(name);
            document.title = name + ' - 考勤系统';
        };
        window.addEventListener('schoolNameUpdated', handleSchoolNameUpdate);

        return () => {
            window.removeEventListener('schoolNameUpdated', handleSchoolNameUpdate);
        };
    }, []);

    useEffect(() => {
        // Fetch wechat status only for teachers
        if (user?.role === 'teacher') {
            axios.get('/wechat/status')
                .then(res => {
                    setShowWechatMenu(res.data?.show_teacher_menu || false);
                })
                .catch(() => {
                    setShowWechatMenu(false);
                });
        }
    }, [user?.role]);

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

    // Check if student is a roll call admin
    const isRollCallAdmin = user?.role === 'student' && user?.student?.is_roll_call_admin;
    // Check if student is a class admin
    const isClassAdmin = user?.role === 'student' && user?.student?.is_class_admin;
    // Check if user has leave approval permission
    const canApproveLeave = hasPermission('leave_requests.approve');
    // Check if user has roll call management permission
    const canManageRollCall = hasPermission('roll_calls.manage');

    const navigation = user?.role === 'student'
        ? [
            { name: '仪表盘', href: '/student/dashboard' },
            { name: '请假申请', href: '/student/request' },
            { name: '我的记录', href: '/student/history' },
            ...(isClassAdmin ? [{ name: '管理班级考勤', href: '/student/class-attendance' }] : []),
            ...(isRollCallAdmin ? [{ name: '点名', href: '/roll-call' }] : []),
        ]
        : [
            { name: '概览', href: '/teacher/dashboard' },
            ...(canApproveLeave ? [{ name: '审批记录', href: '/teacher/approvals' }] : []),
            { name: '学生管理', href: '/teacher/students' },
            ...(canManageRollCall ? [{ name: '点名', href: '/roll-call' }] : []),
            ...(showWechatMenu ? [{ name: '微信推送', href: '/teacher/wechat' }] : []),
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
                                        <span className="text-white font-bold text-xl">🛡️ {schoolName}</span>
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
                                        {/* User Dropdown Menu */}
                                        <Menu as="div" className="relative">
                                            <Menu.Button className="flex items-center gap-1 text-indigo-200 hover:text-white text-sm transition-colors">
                                                <span>你好, {user?.name}</span>
                                                <ChevronDownIcon className="h-4 w-4" />
                                            </Menu.Button>
                                            <Transition
                                                as={Fragment}
                                                enter="transition ease-out duration-100"
                                                enterFrom="transform opacity-0 scale-95"
                                                enterTo="transform opacity-100 scale-100"
                                                leave="transition ease-in duration-75"
                                                leaveFrom="transform opacity-100 scale-100"
                                                leaveTo="transform opacity-0 scale-95"
                                            >
                                                <Menu.Items className="absolute right-0 z-10 mt-2 w-40 origin-top-right rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                                    <Menu.Item>
                                                        {({ active }) => (
                                                            <button
                                                                onClick={() => setShowPasswordModal(true)}
                                                                className={classNames(
                                                                    active ? 'bg-gray-100' : '',
                                                                    'flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700'
                                                                )}
                                                            >
                                                                <KeyIcon className="h-4 w-4" />
                                                                修改密码
                                                            </button>
                                                        )}
                                                    </Menu.Item>
                                                    <Menu.Item>
                                                        {({ active }) => (
                                                            <button
                                                                onClick={handleLogout}
                                                                className={classNames(
                                                                    active ? 'bg-gray-100' : '',
                                                                    'flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700'
                                                                )}
                                                            >
                                                                <ArrowRightOnRectangleIcon className="h-4 w-4" />
                                                                退出登录
                                                            </button>
                                                        )}
                                                    </Menu.Item>
                                                </Menu.Items>
                                            </Transition>
                                        </Menu>
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
                                    onClick={() => setShowPasswordModal(true)}
                                    className="flex items-center gap-2 w-full text-left rounded-md px-3 py-2 text-base font-medium text-white hover:bg-indigo-500 hover:bg-opacity-75"
                                >
                                    <KeyIcon className="h-5 w-5" />
                                    修改密码
                                </button>
                                <button
                                    onClick={handleLogout}
                                    className="flex items-center gap-2 w-full text-left rounded-md px-3 py-2 text-base font-medium text-white hover:bg-indigo-500 hover:bg-opacity-75"
                                >
                                    <ArrowRightOnRectangleIcon className="h-5 w-5" />
                                    退出登录
                                </button>
                            </div>
                        </Disclosure.Panel>
                    </>
                )}
            </Disclosure>


            <main>
                <div className="mx-auto max-w-7xl py-6 sm:px-6 lg:px-8">
                    {children}
                </div>
            </main>


            {/* Change Password Modal */}
            <ChangePasswordModal
                isOpen={showPasswordModal}
                onClose={() => setShowPasswordModal(false)}
            />
        </div>
    );
}
