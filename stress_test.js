import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { Trend, Counter } from 'k6/metrics';

let redisReserveTime = new Trend('redis_reserve_time_ms');
let phpProcessingTime = new Trend('php_processing_time_ms');
let nginxTotalTime = new Trend('nginx_total_time_ms');
let nginxUpstreamTime = new Trend('nginx_upstream_time_ms');

let logicalErrors = new Counter('errors_logical_out_of_stock');
let technicalErrors = new Counter('errors_technical_server_crash');

export const options = {
    vus: 100,
    duration: '30s',
    noConnectionReuse: true,
};

const BASE_URL = 'http://127.0.0.1:8080/api';
const SEARCH_TERMS = ['laptop', 'phone', 'shoes', 'watch', 'camera', 'samsung', 'apple', 'bag'];

export function setup() {
    let tokens = [];
    let requests = [];
    console.log('Starting login for 100 users... Please wait!');

    for (let i = 1; i <= 100; i++) {
        let req = {
            method: 'POST',
            url: `${BASE_URL}/auth/login`,
            body: JSON.stringify({ email: `user${i}@test.com`, password: 'password123' }),
            params: { headers: { 'Content-Type': 'application/json' } }
        };
        requests.push(req);
    }

    console.log(' Sending all login requests in batches...');
    let responses = http.batch(requests);

    responses.forEach((res, index) => {
        if (res.status === 200) {
            tokens.push(res.json().token || res.json().access_token);
        } else {
            console.error(` Login failed for user${index + 1} - Status: ${res.status}`);
        }
    });

    console.log(` Collected ${tokens.length} tokens. Starting test!`);

    if (tokens.length === 0) {
        fail('There is no users logged in 100% Fail');
    }

    return tokens;
}

export default function (tokens) {
    let userToken = tokens[__VU - 1];

    if (!userToken) {
        sleep(1);
        return;
    }

    const params = {
        headers: {
            'Authorization': `Bearer ${userToken}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    let keyword = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
    let searchRes = http.get(`${BASE_URL}/search?q=${keyword}`, params);
    check(searchRes, { 'Search Success (200)': (r) => r.status === 200 });
    sleep(1);

    let cartPayload = JSON.stringify({ product_id: 1, quantity: 1 });
    let cartAddRes = http.post(`${BASE_URL}/cart/add`, cartPayload, params);

    let isSuccessCart = cartAddRes.status === 200 || cartAddRes.status === 201;
    let isAlreadyInCart = cartAddRes.status === 409;

    check(cartAddRes, {
        'Cart Ready (Added or Already Exists)': (r) => isSuccessCart || isAlreadyInCart,
    });

    if (!isSuccessCart && !isAlreadyInCart) {
        if (cartAddRes.status >= 500 || cartAddRes.status === 0) {
            technicalErrors.add(1);
            console.error(` [Technical Error - Cart] VU: ${__VU} | Status: ${cartAddRes.status}`);
        }
        return;
    }
    sleep(1);

    let getCartRes = http.get(`${BASE_URL}/cart`, params);
    let cartData = getCartRes.json();
    let cartItemIds = [];

    if (cartData && cartData.data) {
        cartItemIds = cartData.data.map(item => item.id);
    } else if (Array.isArray(cartData)) {
        cartItemIds = cartData.map(item => item.id);
    }

    if (cartItemIds.length > 0) {
        let orderPayload = JSON.stringify({
            items: cartItemIds,
            scenario: 'success'
        });

        let orderRes = http.post(`${BASE_URL}/orders/create`, orderPayload, params);

        let isSuccessOrder = orderRes.status === 201;
        let isLogicalError = orderRes.status === 400;
        let isTechnicalError = orderRes.status >= 500 || orderRes.status === 0;

        check(orderRes, {
            'Order Success (201)': () => isSuccessOrder,
            'Logical Failure - Out of Stock (400)': () => isLogicalError,
            'Technical Failure - Server Crash (5xx)': () => isTechnicalError,
        });

        if (isLogicalError) {
            logicalErrors.add(1);
        }

        if (isTechnicalError) {
            technicalErrors.add(1);
            let errorBody = orderRes.body ? orderRes.body.substring(0, 150) : 'No Body';
            console.error(`[Technical Failure] VU: ${__VU} | Status: ${orderRes.status} | Body: ${errorBody}`);
        }

        if (isSuccessOrder || isLogicalError) {
            try {
                let body = orderRes.json();
                if (body && body.benchmarks) {
                    if (body.benchmarks.redis_reserve_time_ms) {
                        redisReserveTime.add(body.benchmarks.redis_reserve_time_ms);
                    }
                    if (body.benchmarks.total_php_time_ms) {
                        phpProcessingTime.add(body.benchmarks.total_php_time_ms);
                    }
                }
            } catch (e) {}
        }

        if (orderRes.headers['X-Request-Time']) {
            nginxTotalTime.add(parseFloat(orderRes.headers['X-Request-Time']) * 1000);
        }
        if (orderRes.headers['X-Upstream-Response-Time']) {
            let upTime = parseFloat(orderRes.headers['X-Upstream-Response-Time']) * 1000;
            if (!isNaN(upTime)) nginxUpstreamTime.add(upTime);
        }
    }
    sleep(1);
}
