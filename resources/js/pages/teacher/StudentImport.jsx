import React, { useState } from 'react';
import Layout from '../../components/Layout';
import axios from 'axios';
import { useDropzone } from 'react-dropzone';
import { CloudArrowUpIcon, DocumentArrowDownIcon } from '@heroicons/react/24/outline';

export default function StudentImport() {
    const [file, setFile] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [message, setMessage] = useState(null);

    // Mock Class ID Select - in reality fetch classes
    const classId = 1; // Default for demo

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
        setUploading(true);

        const formData = new FormData();
        formData.append('file', file);
        formData.append('class_id', classId);

        try {
            await axios.post('/students/import', formData, {
                headers: { 'Content-Type': 'multipart/form-data' }
            });
            setMessage({ type: 'success', text: 'Import successful! Students created.' });
            setFile(null);
        } catch (error) {
            console.error(error);
            setMessage({ type: 'error', text: 'Import failed. Check format.' });
        } finally {
            setUploading(false);
        }
    };

    const downloadTemplate = async () => {
        // In real app, trigger download.
        // We can simulate or point to API.
        alert("Mock: Downloading Template...");
    };

    return (
        <Layout>
            <div className="max-w-2xl mx-auto">
                <h2 className="text-2xl font-bold mb-6">Import Students</h2>

                <div className="bg-white p-6 rounded-lg shadow mb-6">
                    <h3 className="font-semibold mb-2">Instructions</h3>
                    <p className="text-sm text-gray-500 mb-4">
                        Upload an Excel file (.xlsx) with the following columns: <code>name</code>, <code>student_no</code>, <code>parent_contact</code>.
                    </p>
                    <button
                        onClick={downloadTemplate}
                        className="inline-flex items-center text-sm text-indigo-600 hover:text-indigo-500"
                    >
                        <DocumentArrowDownIcon className="h-4 w-4 mr-1" />
                        Download Template
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
                        <p className="mt-2 text-sm text-gray-500">Drag & drop Excel file here, or click to select</p>
                    )}
                </div>

                <div className="mt-6 flex justify-end">
                    <button
                        onClick={handleUpload}
                        disabled={!file || uploading}
                        className="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none disabled:opacity-50"
                    >
                        {uploading ? 'Uploading...' : 'Start Import'}
                    </button>
                </div>
            </div>
        </Layout>
    );
}
