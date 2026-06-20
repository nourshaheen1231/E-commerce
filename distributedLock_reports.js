import http from 'k6/http';

export const options = {
    vus: 3,
    iterations: 3,
};

export default function () {
    http.post(
        'http://127.0.0.1:8080/api/test-report',
        null,
        {
            headers: {
                'Accept': 'application/json',
            },
        }
    );
}
