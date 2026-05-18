import http from 'k6/http';
import { check } from 'k6';

export let options = {
    vus: 20,
    iterations: 20,
    // duration: '1m',
};

const BASE_URL = 'http://ppp.test';

const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vcHBwLnRlc3QvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3NzkwODM5NDUsImV4cCI6MTc3OTA4NzU0NSwibmJmIjoxNzc5MDgzOTQ1LCJqdGkiOiJFUnVwTXA1UEU4TmdjQnhvIiwic3ViIjoiMjAzIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.-mL7Mo6QMk0mxs1QsflvRyt7JshJ4-Ifk4oy72wF81E';

const params = {
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${TOKEN}`,
    },
};

export default function () {

    // CREATE ORDER
    let orderPayload = JSON.stringify({
        items: [14]
    });

    let orderRes = http.post(
        `${BASE_URL}/api/orders/create`,
        orderPayload,
        params
    );

    check(orderRes, {
        'order created': (r) => r.status === 201,
    });

    if (orderRes.status !== 201) {
        console.log('Order failed: ' + orderRes.body);
        return;
    }

    let orderData = JSON.parse(orderRes.body);

    let orderId = orderData.order_id;

    // CREATE PAYMENT JOB
    let paymentPayload = JSON.stringify({
        order_id: orderId,
        scenario: 'success'
    });

    let paymentRes = http.post(
        `${BASE_URL}/api/payment/create-intent`,
        paymentPayload,
        params
    );

    check(paymentRes, {
        'payment queued': (r) => r.status === 202,
    });

    console.log(`Order ${orderId} queued`);
}