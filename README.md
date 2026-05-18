# 🛒 E-Commerce Advanced Backend Platform

An enterprise-grade, high-performance E-commerce backend built with Laravel, focusing on optimizing big data processing and asynchronous workflows. The architecture incorporates advanced software engineering patterns such as **Asynchronous Job Chaining**, **Data Chunking**, and **Automated Load Testing** to ensure maximum throughput and sub-second response times.

---

## Architectural Highlights & Features

1. **High-Performance Analytics Chunking (`DailySalesAnalyticsJob`)**: 
   Processes massive accounting data datasets sequentially (chunks of 500 records) utilizing database indexing hooks to prevent RAM exhaustion and eliminate memory leaks.
2. **Asynchronous Payment Flow (`ProcessOrder`)**: 
   Decoupled multi-stage system integration using Stripe API. Frees up the HTTP request lifecycle immediately with a `202 Accepted` response.
3. **Asynchronous Job Chaining (`GenerateInvoicePDF`)**: 
   Offloads CPU-intensive PDF creation tasks entirely to a background processing queue powered by Redis and monitored dynamically via Laravel Horizon.

---

## Prerequisites & System Requirements

Before initializing the installation, ensure your environment satisfies the following specs:
* **PHP**: `^8.2` (with standard CLI and FPM extensions enabled)
* **Composer**: `^2.6`
* **Database**: MySQL `^8.0`
* **In-Memory Store**: Redis Server (Active and running on port `6379`)
* **Load Testing Tool**: k6 CLI installed locally

---

## Installation & Setup Guide

Follow these sequential steps to clone, configure, and boot the development environment locally:

### 1. Clone the Repository
```bash
git clone [https://github.com/nourshaheen1231/E-commerce.git](https://github.com/nourshaheen1231/E-commerce.git)
cd E-commerce

### 2. Install PHP Dependencies
composer install

3. Environment Configuration
Copy the template file to initialize environment variables:
```bash
cp .env.example .env

Open your .env file and set up your MySQL and Redis configurations correctly:
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ppp
DB_USERNAME=root
DB_PASSWORD=your_password

QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

4. System Key & Token Generation
Generate the application secure key and JWT secret for user authentication tokens:

Bash
php artisan key:generate
php artisan jwt:secret

5. Initialize Database Schema & Core Tables
Run the database migrations and seeders to populate initial products and system state:

Bash
php artisan migrate --seed

6. Prepare Laravel Subsystem Tables (Cache & Queue Worker Support)
Ensure system state tables exist to allow Horizon worker processes to monitor tasks properly:

Bash
php artisan make:cache-table
php artisan queue:table
php artisan migrate

7. Clear Configuration and Optimization Cache
Bash
php artisan optimize:clear

⚙️ Running Background Services
To witness the asynchronous operations, the background services must be booted in separate terminal windows:

Ensure Redis is Active:

Bash
sudo service redis-server start
Launch Laravel Horizon Monitor:

Bash
php artisan horizon
🧪 Automated QA & Performance Testing Suite
This repository contains robust mechanisms to prove performance metrics under heavy load.

Test A: Big Data Processing Verification (Data Chunking)
To prove that 10,000+ orders are processed safely without memory overflow:

Fire the job synchronously from the interactive shell:

Bash
php artisan tinker
>>> \App\Jobs\DailySalesAnalyticsJob::dispatchSync();
Verify the structural chunk separation inside system logs:

Bash
tail -n 20 storage/logs/laravel.log
Expected Output: Serial lines printing === [Chunk Detected] === number of chunks in batch 500.

Test B: Non-Blocking Asynchronous Payments (k6 Load Test)
To verify that CPU-heavy invoice generation doesn't slow down the checkout API:

Create a script named pdf_async_test.js using your valid JWT Bearer token.

Execute the workload simulation injection using 3 parallel concurrent users:

Bash
k6 run pdf_async_test.js

Read the combined proof metrics:

k6 terminal: Will print HTTP response times under ~50ms-100ms returning 202 Accepted.

laravel.log: Will prove that the invoice PDF took exactly 5 seconds (sleep(5)) to cook inside the background runner, entirely independent of the user's fast response time.
**ملف التقرير:** [عرض التقرير / تنزيل PDF](./تقرير مشروع البرمجة المتوازية 2026.pdf)
