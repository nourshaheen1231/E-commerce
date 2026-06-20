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
        items: [11]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vMTI3LjAuMC4xOjgwODAvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3ODE4NzkzOTAsImV4cCI6MTc4MTg4Mjk5MCwibmJmIjoxNzgxODc5MzkwLCJqdGkiOiJxM2plZDVnVFpUMmFpaUZTIiwic3ViIjoiMjAyIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.jboNLlSYSipvfdPwP9RAiNnxm2AnHR3DNHjbd3ZxB80',
        },
    };

    const res = http.post(url, payload, params);

    check(res, {
        'Success (201)': (r) => r.status === 201,
        'Blocked (423)': (r) => r.status === 423,
    });

    sleep(1);
}
