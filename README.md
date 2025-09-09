# Flashcards Study Platform Plugin

This WordPress plugin adds a full flashcard and study platform with custom post types, REST endpoints, study sessions, classes, and more.

## Features
- **Decks and Cards:** Manage decks (`fsp_deck`) and cards (`fsp_card`) with REST endpoints for listing and creation.
- **Study Sessions:** `POST /wp-json/fsp/v1/study/sessions` starts a session and `PATCH /wp-json/fsp/v1/study/sessions/<id>` updates stats.
- **Reviews:** `POST /wp-json/fsp/v1/study/reviews` records spaced repetition data using SM-2.
- **Classes & Assignments:** Teachers can create classes, students can join via code, assignments can be created, and gradebooks viewed.
- **Ratings & Comments:** Endpoints for rating decks, commenting, reporting content, and searching the public library.
- **Shortcode:** `[flashcard_deck id="123"]` renders interactive cards on a page.

## Installation
1. Copy the plugin folder into your WordPress `wp-content/plugins` directory.
2. Activate **Flashcards Study Platform** from the Plugins screen.

## Development
Run a basic syntax check:
```bash
php -l flashcards-study-platform.php
```

