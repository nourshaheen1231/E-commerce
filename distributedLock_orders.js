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
        items: [592]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vMTI3LjAuMC4xOjgwODAvYXBpL2F1dGgvcmVnaXN0ZXIiLCJpYXQiOjE3ODE5OTgxNjksImV4cCI6MTc4MjAwMTc2OSwibmJmIjoxNzgxOTk4MTY5LCJqdGkiOiJCM2R4c2Y2UEdYaXQ4NFdaIiwic3ViIjoiMTAxIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.n_sz9b3KT8MXR_GhzTG-99IA_vEzUN913mfObunhrVA',
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
