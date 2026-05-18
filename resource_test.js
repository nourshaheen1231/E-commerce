import http from 'k6/http';
import { check } from 'k6';

export let options = {
    vus: 50,
    iterations: 50,
    // duration: '1m',
};

const BASE_URL = 'http://ppp.test';

const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vcHBwLnRlc3QvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3NzkxMjAwMDgsImV4cCI6MTc3OTEyMzYwOCwibmJmIjoxNzc5MTIwMDA4LCJqdGkiOiJXeGxPMVBub2p6RDU5UlVCIiwic3ViIjoiMjAzIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.HrhJ9OKiofl9JhARfZlCW0nIYCIO4q7_CzxK08VC4rU';

const params = {
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${TOKEN}`,
    },
};

export default function () {
    // 1. CREATE ORDER
    let orderPayload = JSON.stringify({
        items: [33]
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
        console.log(`Order failed (Status ${orderRes.status}): ` + orderRes.body);
        return;
    }

    let orderData;
    try {
        orderData = JSON.parse(orderRes.body);
    } catch (e) {
        console.log("Failed to parse order response JSON: " + orderRes.body);
        return;
    }

    let orderId = orderData.order_id;

    // 2. CREATE PAYMENT JOB
    let paymentPayload = JSON.stringify({
        order_id: orderId,
        scenario: 'success'
    });

    let paymentRes = http.post(
        `${BASE_URL}/api/payment/create-intent`,
        paymentPayload,
        params
    );

    let isQueued = check(paymentRes, {
        'payment queued': (r) => r.status === 202,
    });

    if (isQueued) {
        console.log(`Order ${orderId} queued successfully`);
    } else {
        console.log(`Payment failed for Order ${orderId} (Status ${paymentRes.status}): ` + paymentRes.body);
    }
}