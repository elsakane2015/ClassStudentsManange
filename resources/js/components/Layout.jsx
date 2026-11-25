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
    // Notification bell state
    const [unreadCount, setUnreadCount] = useState(0);
    const [recentNotifs, setRecentNotifs] = useState([]);
    const [showNotifPanel, setShowNotifPanel] = useState(false);

    const fetchBellNotifications = async () => {
        if (!user) return;
        try {
            const res = await axios.get('/notifications', { params: { per_page: 8 } });
            setRecentNotifs(res.data?.data || []);
            setUnreadCount(res.data?.unread_count || 0);
        } catch {
            // Silent fail when table or endpoint unavailable
        }
    };

    useEffect(() => {
        if (!user) return undefined;
        fetchBellNotifications();
        const timer = setInterval(fetchBellNotifications, 60000);
        const onUpdated = () => fetchBellNotifications();
        window.addEventListener('notificationsUpdated', onUpdated);
        return () => {
            clearInterval(timer);
            window.removeEventListener('notificationsUpdated', onUpdated);
        };
    }, [user?.id]);

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
    const canTakeEveningStudy = ['duty_teacher', 'system_admin', 'school_admin', 'department_manager', 'manager'].includes(user?.role)
        || (user?.duty_departments?.length || 0) > 0;
    const eveningStudyMenu = hasPermission('evening_study.view')
        ? [{
            name: '夜自习',
            children: [
                { name: '夜自习情况', href: '/evening-study/overview' },
                ...(canTakeEveningStudy ? [{ name: '夜自习点名', href: '/evening-study/take' }] : []),
            ],
        }]
        : [];

    const hasParentChildren = Boolean(user?.has_parent_children || (user?.parent_children?.length || 0) > 0);

    const navigation = user?.role === 'parent'
        ? [
            { name: '孩子动态', href: '/parent/dashboard' },
            { name: '孩子考勤', href: '/parent/attendance' },
        ]
        : user?.role === 'student'
        ? [
            { name: '仪表盘', href: '/student/dashboard' },
            { name: '请假申请', href: '/student/request' },
            { name: '我的记录', href: '/student/history' },
            ...(isClassAdmin ? [{ name: '管理班级考勤', href: '/student/class-attendance' }] : []),
            ...(isRollCallAdmin ? [{ name: '点名', href: '/roll-call' }] : []),
        ]
        : user?.role === 'duty_teacher'
        ? [
            { name: '夜自习点名', href: '/evening-study/take' },
            ...(hasParentChildren ? [{ name: '我的孩子', href: '/parent/dashboard' }] : []),
        ]
        : [
            { name: '概览', href: '/teacher/dashboard' },
            ...(canApproveLeave ? [{ name: '审批记录', href: '/teacher/approvals' }] : []),
            { name: '学生管理', href: '/teacher/students' },
            ...(canManageRollCall ? [{ name: '点名', href: '/roll-call' }] : []),
            ...eveningStudyMenu,
            ...(showWechatMenu ? [{ name: '微信推送', href: '/teacher/wechat' }] : []),
            ...(user?.role === 'teacher' ? [{ name: '家长通知', href: '/teacher/email-notifications' }] : []),
            ...(hasParentChildren ? [{ name: '我的孩子', href: '/parent/dashboard' }] : []),
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
                                            {navigation.map((item) => item.children ? (
                                                <Menu as="div" key={item.name} className="relative">
                                                    <Menu.Button className={classNames(
                                                        item.children.some(child => location.pathname === child.href)
                                                            ? 'bg-indigo-700 text-white'
                                                            : 'text-white hover:bg-indigo-500 hover:bg-opacity-75',
                                                        'inline-flex items-center gap-1 rounded-md px-3 py-2 text-sm font-medium'
                                                    )}>
                                                        {item.name}<ChevronDownIcon className="h-4 w-4" />
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
                                                        <Menu.Items className="absolute left-0 z-20 mt-2 w-40 origin-top-left rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none">
                                                            {item.children.map(child => <Menu.Item key={child.href}>{({ active }) => <Link to={child.href} className={classNames(active || location.pathname === child.href ? 'bg-gray-100 text-indigo-700' : 'text-gray-700', 'block px-4 py-2 text-sm')}>{child.name}</Link>}</Menu.Item>)}
                                                        </Menu.Items>
                                                    </Transition>
                                                </Menu>
                                            ) : (
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
                                    <div className="ml-4 flex items-center gap-4 md:ml-6">
                                        {/* In-App Notification Bell */}
                                        <div className="relative">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    const next = !showNotifPanel;
                                                    setShowNotifPanel(next);
                                                    if (next) fetchBellNotifications();
                                                }}
                                                className="relative rounded-full p-1.5 text-indigo-200 hover:bg-indigo-500 hover:text-white transition"
                                                title="消息通知"
                                            >
                                                <BellIcon className="h-5 w-5" />
                                                {unreadCount > 0 && (
                                                    <span className="absolute -top-0.5 -right-0.5 inline-flex min-w-[18px] h-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white">
                                                        {unreadCount > 99 ? '99+' : unreadCount}
                                                    </span>
                                                )}
                                            </button>
                                            {showNotifPanel && (
                                                <div className="absolute right-0 z-30 mt-2 w-80 rounded-xl bg-white shadow-xl ring-1 ring-black/10 overflow-hidden">
                                                    <div className="flex items-center justify-between px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                                                        <span className="text-sm font-semibold text-gray-800">消息通知</span>
                                                        <div className="flex items-center gap-2">
                                                            {unreadCount > 0 && (
                                                                <button
                                                                    type="button"
                                                                    onClick={async () => {
                                                                        await axios.post('/notifications/read-all');
                                                                        setUnreadCount(0);
                                                                        setRecentNotifs(prev => prev.map(n => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
                                                                        window.dispatchEvent(new CustomEvent('notificationsUpdated'));
                                                                    }}
                                                                    className="text-xs text-indigo-600 hover:text-indigo-800"
                                                                >
                                                                    全部已读
                                                                </button>
                                                            )}
                                                            <button
                                                                type="button"
                                                                onClick={() => setShowNotifPanel(false)}
                                                                className="text-xs text-gray-400 hover:text-gray-600"
                                                            >
                                                                关闭
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div className="max-h-80 overflow-y-auto divide-y divide-gray-100">
                                                        {recentNotifs.length === 0 ? (
                                                            <div className="p-6 text-center text-xs text-gray-400">暂无消息通知</div>
                                                        ) : recentNotifs.map(n => (
                                                            <div
                                                                key={n.id}
                                                                onClick={async () => {
                                                                    if (!n.read_at) {
                                                                        await axios.post(`/notifications/${n.id}/read`).catch(() => {});
                                                                        setUnreadCount(c => Math.max(0, c - 1));
                                                                        setRecentNotifs(prev => prev.map(x => x.id === n.id ? { ...x, read_at: new Date().toISOString() } : x));
                                                                        window.dispatchEvent(new CustomEvent('notificationsUpdated'));
                                                                    }
                                                                    setShowNotifPanel(false);
                                                                    const route = n.data?.route;
                                                                    if (route === 'parent_child_leaves' || route === 'parent_child_attendance') {
                                                                        navigate('/parent/dashboard');
                                                                    } else if (route === 'leave_approvals') {
                                                                        navigate('/teacher/approvals');
                                                                    } else if (route === 'leave_history') {
                                                                        navigate('/student/history');
                                                                    }
                                                                }}
                                                                className={`px-4 py-3 cursor-pointer hover:bg-gray-50 ${!n.read_at ? 'bg-indigo-50/40' : ''}`}
                                                            >
                                                                <p className={`text-xs ${!n.read_at ? 'font-semibold text-gray-900' : 'text-gray-700'}`}>{n.title}</p>
                                                                <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">{n.body}</p>
                                                                <p className="text-[11px] text-gray-400 mt-1">{n.created_at}</p>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
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
                                {navigation.map((item) => item.children ? (
                                    <div key={item.name} className="space-y-1">
                                        <div className="px-3 py-2 text-sm font-semibold text-indigo-200">{item.name}</div>
                                        {item.children.map(child => <Link key={child.href} to={child.href} className={classNames(location.pathname === child.href ? 'bg-indigo-700 text-white' : 'text-white hover:bg-indigo-500 hover:bg-opacity-75', 'block rounded-md py-2 pl-6 pr-3 text-base font-medium')}>{child.name}</Link>)}
                                    </div>
                                ) : (
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
