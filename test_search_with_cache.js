import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 50,
    duration: '30s',
    noConnectionReuse: true,
};

const BASE_URL = 'http://127.0.0.1:8080/api/search';
const TOKEN = "Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vMTI3LjAuMC4xOjgwODAvYXBpL2F1dGgvbG9naW4iLCJpYXQiOjE3ODIwMjU4MDEsImV4cCI6MTc4MjAyOTQwMSwibmJmIjoxNzgyMDI1ODAxLCJqdGkiOiJMWFV4SlV6elpHOFRYYkpOIiwic3ViIjoiMTAxIiwicHJ2IjoiMjNiZDVjODk0OWY2MDBhZGIzOWU3MDFjNDAwODcyZGI3YTU5NzZmNyJ9.-cGxYIp-AyZJq9-yHEbkNi9WFr1O9NjYfH1T9dYtC3U";

const SEARCH_TERMS = ['laptop', 'phone', 'shoes', 'watch', 'camera', 'samsung', 'apple', 'bag'];

export default function () {
    let keyword = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];

    const params = {
        headers: {
            // 'Authorization': TOKEN,
            'Content-Type': 'application/json',
            'Accept': 'application/json',
        },
    };

    let res = http.get(`${BASE_URL}?q=${keyword}`, params);

    check(res, {
        'status is 200': (r) => {
            if (r.status !== 200) {
                console.log(`Failed with status: ${r.status} - Query: ${keyword}`);
            }
            return r.status === 200;
        },
    });

    sleep(0.1);
}
