
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 1000,
    duration: '30s',
};

const BASE_URL = 'http://127.0.0.1:8080/api/showTopselling';

export default function () {
    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    let res = http.get(BASE_URL, params);

    check(res, {
        'status is 200': (r) => {
            if (r.status !== 200) console.log(`Failed with status: ${r.status}`);
            return r.status === 200;
        },
    });

    sleep(0.1);
}
