import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 3,       // 3 مستخدمين فقط
    iterations: 3 // 3 طلبات إجمالية
};

export default function () {
    const orderId = 1001 + __VU;

    const payload = JSON.stringify({
        order_id: orderId,
        scenario: 'success'
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL3JlZ2lzdGVyIiwiaWF0IjoxNzc5MTEyNjY4LCJleHAiOjE3NzkxMTYyNjgsIm5iZiI6MTc3OTExMjY2OCwianRpIjoiZ0dsWHMweE9CVXVLb0Q4RSIsInN1YiI6IjIwMiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.Q-X6jw7VZ_sLIKExlaY-hrYkovFRVjr1MGbaene5sLU'
        },
    };

    let res = http.post(
        'http://localhost/api/payment/create-intent',
        payload,
        params
    );

    check(res, {
        'is status 202 (Accepted)': (r) => r.status === 202,
        'response is fast (< 500ms)': (r) => r.timings.duration < 500,
    });

    console.log(`[k6] API Response for Order ${orderId}: ${res.status} | Time: ${Math.round(res.timings.duration)}ms`);
}
