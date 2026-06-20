import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 50,
    duration: '30s',
    noConnectionReuse: true,
};

const BASE_URL = 'http://127.0.0.1:8080/api/search';
const TOKEN = "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL3JlZ2lzdGVyIiwiaWF0IjoxNzgxOTY0ODgwLCJleHAiOjE3ODE5Njg0ODAsIm5iZiI6MTc4MTk2NDg4MCwianRpIjoieDhnYTZTWjl4WkVFemNWUiIsInN1YiI6IjEwMSIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.sRSDiQQyrt39zzvLUZTPLlQg1Bqp8m7wuWgEjhlfOQU";

const SEARCH_TERMS = ['laptop', 'phone', 'shoes', 'watch', 'camera', 'samsung', 'apple', 'bag'];

export default function () {
    let keyword = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];

    const params = {
        headers: {
            // 'Authorization': TOKEN,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    let res = http.get(`${BASE_URL}?q=${keyword}`, params);

    check(res, {
        'status is 200': (r) => {
            if (r.status !== 200) {
                console.log(`Failed with status: ${r.status} - Query: ${keyword}`);
            }
            return r.status === 200;
        },
    });

    sleep(0.1);
}
