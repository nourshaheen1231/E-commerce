import http from 'k6/http';
import { check } from 'k6';

export let options = {
    vus: 20,
    iterations: 20,
    // duration: '1m',
};

const BASE_URL = 'http://localhost';

const TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL2xvZ2luIiwiaWF0IjoxNzc5MDk1NTU1LCJleHAiOjE3NzkwOTkxNTUsIm5iZiI6MTc3OTA5NTU1NSwianRpIjoiWTBVYVZ4NVVXU0hEQWVsWiIsInN1YiI6IjIwMiIsInBydiI6IjIzYmQ1Yzg5NDlmNjAwYWRiMzllNzAxYzQwMDg3MmRiN2E1OTc2ZjcifQ.eGM-UHSPcpvc_xFahJF-3ai1A4_C1HuodqCHC5t3ymQ';

const params = {
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${TOKEN}`,
    },
};

export default function () {

    // CREATE ORDER
    let orderPayload = JSON.stringify({
        items: [3]
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
