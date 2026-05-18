import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
    // duration: '30s',
};

export default function () {

    const payload = JSON.stringify({
        items: [16]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vcHBwLnRlc3QvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3NzkxMDIwODAsImV4cCI6MTc3OTEwNTY4MCwibmJmIjoxNzc5MTAyMDgwLCJqdGkiOiJSb01OMW1PMkV2QWkxdTFwIiwic3ViIjoiMjAzIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.Yy_Lgu0cghgFmyRLduEa9a4CTDvV4pT0Za9pKWJkMqQ'
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
