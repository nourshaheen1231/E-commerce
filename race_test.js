import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
    // duration: '30s',
};

export default function () {

    const payload = JSON.stringify({
        items: [1]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL3JlZ2lzdGVyIiwiaWF0IjoxNzc5MDkwOTU2LCJleHAiOjE3NzkwOTQ1NTYsIm5iZiI6MTc3OTA5MDk1NiwianRpIjoia09peXplWFdYbXM5MEgyeSIsInN1YiI6IjIwMiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.U3m6NmA_yHuFCdgMXKa9wRZJfgurRQ3QqYbyX8e7Vr4'
        },
    };

    let res = http.post(
        'http://localhost/api/orders/create',
        payload,
        params
    );

    // check(res, {
    //     'status exists': (r) => r.status > 0,
    // });
    console.log(res.status);
    console.log(res.body);
}

// k6 run race_test.js
