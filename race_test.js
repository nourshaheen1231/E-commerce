import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
    // duration: '30s',
};

export default function () {

    const payload = JSON.stringify({
        items: [38]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0OjgwMDAvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3Nzg1OTY3NjcsImV4cCI6MTc3ODYwMDM2NywibmJmIjoxNzc4NTk2NzY3LCJqdGkiOiIxNUJSQ1lTSXlHdEw3VGQwIiwic3ViIjoiMSIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.rx8qPA-hhOd8V1h6T0UIGmsjm3FZVzKTIXkev0fQjw0'
        },
    };

    let res = http.post(
        'http://localhost:8000/api/orders/create',
        payload,
        params
    );

    // check(res, {
    //     'status exists': (r) => r.status > 0,
    // });
    console.log(res.status);
    console.log(res.body);
}