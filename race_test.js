import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
    // duration: '30s',
};

export default function () {

    const payload = JSON.stringify({
        items: [11]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vcHBwLnRlc3QvYXBpL2F1dGgvcmVnaXN0ZXIiLCJpYXQiOjE3Nzg4NTA5MDMsImV4cCI6MTc3ODg1NDUwMywibmJmIjoxNzc4ODUwOTAzLCJqdGkiOiJ1cThzV2hEcm1hWmpqbzZ6Iiwic3ViIjoiMjAyIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.2wxq-QaI2SDahPceSlHc3XqKvmKeEv7w6fOsc-RT-Q4'
        },
    };

    let res = http.post(
        'http://ppp.test/api/orders/create',
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