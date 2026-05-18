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
