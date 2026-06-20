import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 100,
    duration: '30s',
    noConnectionReuse: true,
};

// const BASE_URL = 'http://localhost/api/cart';
const BASE_URL = 'http://127.0.0.1:8080/api/cart';

const TOKEN = "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL2xvZ2luIiwiaWF0IjoxNzgxOTY2MDU0LCJleHAiOjE3ODE5Njk2NTQsIm5iZiI6MTc4MTk2NjA1NCwianRpIjoiYnJoUUJjQnRLakpWSVpuSiIsInN1YiI6IjEwMSIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.DLP2CkYdxhmdI_OC7v_aHh6DHkCQXlklQp-ugjv_fjE";
export default function () {
    const params = {
        headers: {
            'Authorization': TOKEN,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            // 'Connection': 'close',
        },
    };

    let res = http.get(BASE_URL, params);

    check(res, {
        // 'status is 200': (r) => r.status === 200,
        'status is 200': (r) => {
            if (r.status !== 200) console.log(`Failed with status: ${r.status}`);
            return r.status === 200;
        },
    });

    sleep(0.1);
}
