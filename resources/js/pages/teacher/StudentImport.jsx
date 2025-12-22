import React, { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { useDropzone } from 'react-dropzone';
import { CloudArrowUpIcon, DocumentArrowDownIcon } from '@heroicons/react/24/outline';
import useAuthStore from '../../store/authStore';

export default function StudentImport() {
    const { user } = useAuthStore();
    const [file, setFile] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [message, setMessage] = useState(null);

    // Filter Options
    const [depts, setDepts] = useState([]);
    const [grades, setGrades] = useState([]);
    const [classes, setClasses] = useState([]);

    // Selections
    const [selectedDept, setSelectedDept] = useState('');
    const [selectedGrade, setSelectedGrade] = useState('');
    const [selectedClass, setSelectedClass] = useState('');

    useEffect(() => {
        fetchOptions();
    }, []);

    useEffect(() => {
        // Fetch classes when filters change or initially
        fetchClasses();
    }, [selectedDept, selectedGrade]);

    const fetchOptions = async () => {
        try {
            const [deptRes, gradeRes] = await Promise.all([
                axios.get('/options/departments'),
                axios.get('/options/grades')
            ]);
            setDepts(deptRes.data);
            setGrades(gradeRes.data);

            // Auto-select if only one option (e.g. Manager with 1 dept)
            if (deptRes.data.length === 1) {
                setSelectedDept(deptRes.data[0].id);
            }
        } catch (error) {
            console.error("Failed to fetch options", error);
        }
    };

    const fetchClasses = async () => {
        // Teachers might not need this if API restricts them, but good for UX
        try {
            const res = await axios.get('/options/classes', {
                params: {
                    department_id: selectedDept || undefined,
                    enrollment_year: selectedGrade || undefined  // Changed from grade_id to enrollment_year
                }
            });
            setClasses(res.data);
            // If teacher only has 1 class, auto select?
            if (res.data.length === 1 && !selectedClass) {
                setSelectedClass(res.data[0].id);
            }
        } catch (error) {
            console.error("Failed to fetch classes", error);
        }
    };

    const onDrop = (acceptedFiles) => {
        setFile(acceptedFiles[0]);
        setMessage(null);
    };

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: {
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
            'text/csv': ['.csv']
        },
        maxFiles: 1
    });

    const handleUpload = async () => {
        if (!file) return;

        // Validation: If not admin/manager, must select class?
        // Actually, let's just warn if no class selected but file might need columns.
        if (!selectedClass && user?.role === 'teacher') {
            setMessage({ type: 'error', text: '请选择要导入的班级。' });
            return;
        }

        setUploading(true);

        const formData = new FormData();
        formData.append('file', file);
        if (selectedClass) formData.append('class_id', selectedClass);
        // Optional: pass context if no class selected
        if (selectedDept) formData.append('department_id', selectedDept);
        if (selectedGrade) formData.append('enrollment_year', selectedGrade);  // Changed from grade_id

        try {
            await axios.post('/students/import', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            setMessage({ type: 'success', text: '导入成功！学生账号已创建。' });
            setFile(null);
        } catch (error) {
            console.error(error);
            const errorMsg = error.response?.data?.error || error.response?.data?.message || '导入失败，请检查文件格式或内容。';
            setMessage({ type: 'error', text: errorMsg });
        } finally {
            setUploading(false);
        }
    };

    const downloadTemplate = async () => {
        try {
            const response = await axios.get('/students/import-template', {
                responseType: 'blob',
            });
            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', 'student_import_template.xlsx');
            document.body.appendChild(link);
            link.click();
            link.remove();
        } catch (error) {
            console.error("Download failed", error);
            alert("下载失败，请联系管理员。");
        }
    };

    return (
        <Layout>
            <div className="max-w-2xl mx-auto">
                <h2 className="text-2xl font-bold mb-6">批量导入学生</h2>

                <div className="bg-white p-6 rounded-lg shadow mb-6">
                    <h3 className="font-semibold mb-4">第一步：选择导入范围</h3>
                    <div className="grid grid-cols-3 gap-4 mb-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700">系部</label>
                            <select
                                value={selectedDept}
                                onChange={(e) => { setSelectedDept(e.target.value); setSelectedClass(''); }}
                                className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                                disabled={depts.length === 0 && user?.role !== 'admin'}
                            >
                                <option value="">{user?.role === 'admin' ? '全校' : (depts.length > 0 ? '选择系部' : '默认')}</option>
                                {depts.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">入学年份</label>
                            <select
                                value={selectedGrade}
                                onChange={(e) => { setSelectedGrade(e.target.value); setSelectedClass(''); }}
                                className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                            >
                                <option value="">所有年份</option>
                                {(() => {
                                    const currentYear = new Date().getFullYear();
                                    const years = [];
                                    for (let year = currentYear; year >= currentYear - 10; year--) {
                                        years.push(<option key={year} value={year}>{year}</option>);
                                    }
                                    return years;
                                })()}
                            </select>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700">班级</label>
                            <select
                                value={selectedClass}
                                onChange={(e) => setSelectedClass(e.target.value)}
                                className="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md"
                            >
                                <option value="">{selectedDept || selectedGrade ? '选择班级' : (classes.length === 0 ? '无可选班级' : '选择班级')}</option>
                                {classes.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                    </div>

                    <h3 className="font-semibold mb-2 mt-6">使用说明</h3>
                    <p className="text-sm text-gray-500 mb-4">
                        <strong>方式 A (推荐)：</strong>上方选择具体班级，Excel 只需包含学生信息。<br />
                        <strong>方式 B (混合导入)：</strong>上方不选班级，Excel 需包含 <code>department_name</code>, <code>grade_name</code>, <code>class_name</code> 列。
                    </p>
                    <button onClick={downloadTemplate} className="text-sm text-indigo-600 hover:text-indigo-500 flex items-center">
                        <DocumentArrowDownIcon className="h-4 w-4 mr-1" /> 下载 Excel 模版
                    </button>
                </div>

                {message && (
                    <div className={`p-4 rounded mb-6 ${message.type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                        {message.text}
                    </div>
                )}

                <div
                    {...getRootProps()}
                    className={`border-2 border-dashed rounded-lg p-12 text-center cursor-pointer transition-colors
                        ${isDragActive ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 hover:border-gray-400'}`}
                >
                    <input {...getInputProps()} />
                    <CloudArrowUpIcon className="mx-auto h-12 w-12 text-gray-400" />
                    {file ? (
                        <p className="mt-2 text-sm text-gray-900 font-medium">{file.name}</p>
                    ) : (
                        <p className="mt-2 text-sm text-gray-500">
                            {selectedClass
                                ? '点击上传该班级的学生名单'
                                : '点击上传包含分班信息的完整学生名单'}
                        </p>
                    )}
                </div>

                <div className="mt-6 flex justify-end">
                    <button
                        onClick={handleUpload}
                        disabled={!file || uploading}
                        className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none disabled:opacity-50"
                    >
                        {uploading ? '上传中...' : '开始导入'}
                    </button>
                </div>
            </div>
        </Layout>
    );
}
