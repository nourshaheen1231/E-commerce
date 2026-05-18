import http from 'k6/http';
import { check } from 'k6';

export const options = {
    vus: 50,
    iterations: 50,
    // duration: '30s',
};

export default function () {

    const payload = JSON.stringify({
        items: [13]
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vcHBwLnRlc3QvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3NzkwODI4MTAsImV4cCI6MTc3OTA4NjQxMCwibmJmIjoxNzc5MDgyODEwLCJqdGkiOiJ5blNWekxuOUVrSkZwWmlGIiwic3ViIjoiMjAzIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.tmpE9nbXs7A9FSaddh5A1qbRuiFR9jq-o2m40w7bzQc'
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
//http://ppp.test/telescope