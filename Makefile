DC = docker compose
ARTISAN = $(DC) exec app php artisan

.PHONY: up down install migrate seed fresh test docs logs shell

up: ## Build and start app, nginx, mysql, redis, horizon, scheduler
	@test -f .env || cp .env.example .env
	$(DC) up -d --build
	$(DC) exec app composer install --no-interaction
	@grep -q '^APP_KEY=base64' .env || $(ARTISAN) key:generate
	$(ARTISAN) migrate --force

down:
	$(DC) down

migrate:
	$(ARTISAN) migrate --force

seed: ## ~12k users, 3 celebrities, 200k posts
	$(DC) exec app php -d memory_limit=1G artisan db:seed --force

fresh:
	$(DC) exec app php -d memory_limit=1G artisan migrate:fresh --seed --force

test:
	$(DC) exec app php artisan test --compact

docs:
	$(ARTISAN) scribe:generate

logs:
	$(DC) logs -f app horizon

shell:
	$(DC) exec app sh
