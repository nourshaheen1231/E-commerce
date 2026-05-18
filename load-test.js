import http from 'k6/http';

export const options = {
  vus: 100,
  duration: '30s',
};

export default function () {
  let res = http.get('http://e-commerce.test:8080/stress-test');

  console.log('STATUS:', res.status);
}
    