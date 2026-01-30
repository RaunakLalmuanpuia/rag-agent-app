#  Smart Policy & Decision Assistant

This project is an internal **AI-powered policy assistant** built with **Laravel, React, and Google Gemini**.

Unlike a basic “search the PDF” chatbot, this assistant is designed to **reason over company rules**. It can look at multiple policies at once, notice conflicts between them, and suggest what an employee should actually do next.

The goal is simple:
**Help employees make decisions — not just find text.**

---

##  What This Assistant Does Well

* **Understands policies, not just keywords**
  Uses Retrieval-Augmented Generation (RAG) with vector search to pull only the most relevant policy sections.

* **Detects conflicts across documents**
  For example, if two handbooks mention different deadlines or approval flows, the assistant flags the inconsistency instead of blindly quoting both.

* **Gives clear next steps**
  Responses are action-oriented (“Report to security first, then inform HR”) instead of long policy excerpts.

* **Agent-based reasoning**
  Built using `LarAgent`, so the AI decides *when* to search the database and *what* to cross-check.

---

##  Tech Stack

* **Backend:** Laravel 12+
* **Frontend:** React + InertiaJS + Tailwind CSS
* **Database:** PostgreSQL with `pgvector`
* **AI Model:** Google Gemini 1.5 Flash
* **Embeddings:** Gemini `text-embedding-004`
* **Agent Framework:** `maestroerror/laragent`
* **PDF Parsing:** `smalot/pdfparser`

---

## 🚀 Installation

### 1. Requirements

* PHP 8.2+
* Node.js & NPM (Node 22.0+)
* PostgreSQL (with `vector` extension enabled)
* Gemini API Key
   [https://aistudio.google.com/](https://aistudio.google.com/)

---

### 2. Project Setup

```bash


composer install
npm install

cp .env.example .env
php artisan key:generate
```

---

### 3. Environment Configuration

Update your `.env` file with database credentials and your Gemini API key:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=policy_db
DB_USERNAME=postgres
DB_PASSWORD=password

GEMINI_API_KEY=your_api_key_here
```

---

### 4. Database Setup

Run migrations to enable `pgvector` and create the required tables:

```bash
php artisan migrate
```

---

## 📄 Policy Ingestion (RAG Pipeline)

Policies are ingested **via an Artisan command** using the `DocumentService`.

### What happens during ingestion:

1. Text is extracted from the PDF.
2. The content is chunked with overlap for better context retention.
3. Each chunk is converted into a vector embedding using Gemini.
4. Embeddings and metadata are stored in PostgreSQL.

###  Command

```bash
php artisan ingest:handbook
```

This approach keeps ingestion **deterministic and auditable**, instead of hiding it behind a UI.

---

## Asking Questions

Users interact with the assistant through a React + Inertia dashboard.

**Example question:**

> *“I’m traveling next week and I lost my access badge. What should I do?”*

**What the assistant does:**

* Searches both the **Security Policy** and **Travel Policy**
* Cross-references relevant sections
* Checks for conflicting instructions
* Returns a single, clear answer with recommended steps

---

## How Reasoning Works

The assistant is implemented as an **AI agent**, not a simple chat wrapper.

* It decides when a vector search is needed
* It can run multiple searches in one conversation
* It synthesizes answers instead of pasting policy text

---

## 📁 Project Structure

```
app/
 ├─ AiAgents/
 │   └─ PolicyAssistant.php # Agent instructions and tools
 ├─ Controllers/
 │   └─ ChatController.php  # Orchastration
 ├─ Services/
 │   └─ DocumentService.php   # PDF parsing, chunking, embeddings
resources/
 └─ js/
     └─ Pages/
         └─ Chat.jsx          # React chat UI (Inertia + Tailwind)
```

---

##  Why This Exists

Most internal policy bots:

* Either dump raw text
* Or hallucinate answers

This project sits in the middle:

* **Grounded in real documents**
* **Opinionated enough to guide decisions**
* **Transparent about where answers come from**

---
