import http from 'k6/http';
import { check, sleep, fail } from 'k6';
import { Trend, Counter } from 'k6/metrics';

export const options = {
    vus: 100,
    duration: '30s',
    noConnectionReuse: true,
};

const BASE_URL = 'http://127.0.0.1:8080/api';
const SEARCH_TERMS = ['laptop', 'phone', 'shoes', 'watch', 'camera', 'samsung', 'apple', 'bag'];

const PRODUCT_CATALOG = Array.from({ length: 20 }, (_, i) => i + 1);

let mysqlLockWaitTime = new Trend('mysql_lock_wait_time_ms');
let phpProcessingTime = new Trend('php_processing_time_ms');
let nginxTotalTime = new Trend('nginx_total_time_ms');
let nginxUpstreamTime = new Trend('nginx_upstream_time_ms');

let logicalErrors = new Counter('errors_logical_out_of_stock');
let technicalErrors = new Counter('errors_technical_server_crash');

function searchRandomKeyword(params) {
    let keyword = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
    let res = http.get(`${BASE_URL}/search?q=${keyword}`, params);
    check(res, { 'Search Success (200)': (r) => r.status === 200 });
}

function addRandomProductsToCart(params) {
    let itemsCount = Math.floor(Math.random() * 4) + 1;
    let selectedProducts = [];
    let requests = [];

    while (selectedProducts.length < itemsCount) {
        let randomProduct = PRODUCT_CATALOG[Math.floor(Math.random() * PRODUCT_CATALOG.length)];
        if (!selectedProducts.includes(randomProduct)) {
            selectedProducts.push(randomProduct);
        }
    }

    selectedProducts.forEach(pid => {
        let quantity = Math.floor(Math.random() * 2) + 1;
        requests.push({
            method: 'POST',
            url: `${BASE_URL}/cart/add`,
            body: JSON.stringify({ product_id: pid, quantity: quantity }),
            params: params
        });
    });

    let batchResponses = http.batch(requests);

    batchResponses.forEach(res => {
        let isSuccess = res.status === 200 || res.status === 201;
        let isAlreadyExist = res.status === 409;
        let isOutOfStock = res.status === 400;

        check(res, { 'Cart Item Added/Exists/Out of Stock': (r) => isSuccess || isAlreadyExist || isOutOfStock });

        if (!isSuccess && !isAlreadyExist && !isOutOfStock && (res.status >= 500 || res.status === 0)) {
            technicalErrors.add(1);
        }
    });
}

function fetchCartItems(params) {
    let res = http.get(`${BASE_URL}/cart`, params);
    let cartData = res.json();
    let cartItems = [];

    let itemsArray = (cartData && cartData.data) ? cartData.data : (Array.isArray(cartData) ? cartData : []);

    cartItems = itemsArray.map(item => ({
        cartItemId: item.id,
        productId: item.product_id || (item.product ? item.product.id : 'Unknown'),
        quantity: item.quantity || 1
    }));

    return cartItems;
}

function processCheckout(params, cartItems, traceId) {
    let itemIdsOnly = cartItems.map(item => item.cartItemId);

    let orderPayload = JSON.stringify({ items: itemIdsOnly, scenario: 'success' });
    let res = http.post(`${BASE_URL}/orders/create`, orderPayload, params);

    let isSuccessOrder = res.status === 201;
    let isLogicalError = res.status === 400;
    let isTechnicalError = res.status >= 500 || res.status === 0;

    check(res, {
        'Order Success (201)': () => isSuccessOrder,
        'Logical Failure - Out of Stock/Validation (400)': () => isLogicalError,
        'Technical Failure - Server Crash (5xx)': () => isTechnicalError,
    });

    if (isLogicalError) logicalErrors.add(1);
    if (isTechnicalError) technicalErrors.add(1);

    let itemsLogString = cartItems.map(i => `P${i.productId}:Q${i.quantity}`).join(', ');


    if (isSuccessOrder) {
        console.log(`[${traceId}] SUCCESS | User ${__VU} bought: [${itemsLogString}]`);
    } else if (isLogicalError) {
        let errorMsg = res.json() ? res.json().product_name : '';
        console.log(`[${traceId}] OUT OF STOCK | User ${__VU} tried to buy: [${itemsLogString}] but failed. Item: ${errorMsg}`);
    } else if (isTechnicalError) {
        console.error(` [${traceId}] CRASH | User ${__VU} hit a 500 error! Body: ${res.body ? res.body.substring(0, 100) : 'N/A'}`);
    }

    if (res.error) {
        console.error(` [${traceId}] CONNECTION ERROR: ${res.error}`);
    } else {
        console.log(` [${traceId}] RESPONSE STATUS: ${res.status}`);
    }

    if (isSuccessOrder || isLogicalError) {
        try {
            let body = res.json();
            if (body && body.benchmarks) {
                if (body.benchmarks['2_row_lock_wait_ms'] !== undefined) {
                    mysqlLockWaitTime.add(body.benchmarks['2_row_lock_wait_ms']);
                }
                if (body.benchmarks.total_php_time_ms !== undefined) {
                    phpProcessingTime.add(body.benchmarks.total_php_time_ms);
                }
            }
        } catch (e) { }
    }

    if (res.headers['X-Request-Time']) nginxTotalTime.add(parseFloat(res.headers['X-Request-Time']) * 1000);
    if (res.headers['X-Upstream-Response-Time']) {
        let upTime = parseFloat(res.headers['X-Upstream-Response-Time']) * 1000;
        if (!isNaN(upTime)) nginxUpstreamTime.add(upTime);
    }
}

export function setup() {
    let tokens = [];
    let requests = [];
    console.log(' Starting login for 100 users... Please wait!');

    for (let i = 1; i <= 100; i++) {
        requests.push({
            method: 'POST',
            url: `${BASE_URL}/auth/login`,
            body: JSON.stringify({ email: `user${i}@test.com`, password: 'password123' }),
            params: { headers: { 'Content-Type': 'application/json' } }
        });
    }

    let responses = http.batch(requests);
    responses.forEach((res, index) => {
        if (res.status === 200) {
            tokens.push(res.json().token || res.json().access_token);
        } else {
            console.error(` Login failed for user${index + 1} - Status: ${res.status}`);
        }
    });

    console.log(` Collected ${tokens.length} tokens. Starting test!`);
    if (tokens.length === 0) fail('There are no users logged in. 100% Fail.');
    return tokens;
}

export default function (tokens) {
    let userToken = tokens[__VU - 1];
    if (!userToken) {
        sleep(1);
        return;
    }

    let traceId = `VU-${__VU}-ITER-${__ITER}`;

    const params = {
        headers: {
            'Authorization': `Bearer ${userToken}`,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Trace-ID': traceId,
        },
    };

    http.del(`${BASE_URL}/cart/clear`, null, params);
    sleep(0.5);

    searchRandomKeyword(params);
    sleep(1);

    addRandomProductsToCart(params);
    sleep(1);

    let cartItems = fetchCartItems(params);

    if (cartItems.length > 0) {
        processCheckout(params, cartItems, traceId);
    }

    sleep(1);
}
