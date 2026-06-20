import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        double_submit: {
            executor: 'per-vu-iterations',
            vus: 10,
            iterations: 1,
            maxDuration: '5s',
        },
    },
};

export default function () {
    const url = 'http://127.0.0.1:8080/api/orders/create';

    const payload = JSON.stringify({
        items: [15]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vMTI3LjAuMC4xOjgwODAvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3ODE5ODczMjgsImV4cCI6MTc4MTk5MDkyOCwibmJmIjoxNzgxOTg3MzI4LCJqdGkiOiJ5V0RRUUFEckwyNkgxZXRsIiwic3ViIjoiMTAxIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.WrpNLuiLEh6NgzB9j266E1becsS2EnAgk8lG47lhTYQ',
        },
    };

    const res = http.post(url, payload, params);

    if (res.status !== 201 && res.status !== 423) {
        console.log(` Failed with Status ${res.status}: ${res.body}`);
    }

    check(res, {
        'Success (201)': (r) => r.status === 201,
        'Blocked (423)': (r) => r.status === 423,
    });

    sleep(1);
}
