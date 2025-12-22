import React, { useEffect, useState } from 'react';
import axios from 'axios';

export default function DebugPage() {
    const [info, setInfo] = useState({});

    useEffect(() => {
        const checkConfig = async () => {
            const debugInfo = {
                windowLocation: window.location.href,
                windowPort: window.location.port,
                axiosBaseURL: axios.defaults.baseURL,
                token: localStorage.getItem('token') ? 'Present' : 'Missing',
                tokenValue: localStorage.getItem('token')?.substring(0, 20) + '...',
            };

            try {
                const response = await axios.get('/attendance/stats?scope=today');
                debugInfo.apiResponse = 'Success';
                debugInfo.data = response.data;
            } catch (error) {
                debugInfo.apiResponse = 'Failed';
                debugInfo.error = error.message;
                debugInfo.errorDetails = error.response?.data;
            }

            setInfo(debugInfo);
        };

        checkConfig();
    }, []);

    return (
        <div style={{ padding: '20px', fontFamily: 'monospace' }}>
            <h1>Debug Information</h1>
            <pre>{JSON.stringify(info, null, 2)}</pre>
        </div>
    );
}
