# Development

## Prerequisites

- Docker and Docker Compose
- Obtain a token by following the instructions at: https://expose.beyondco.de/docs/introduction to get a token

## Setup

1. Clone the repository:
    ```console
    git clone https://github.com/icepay/woocommerce.git
    ```
2. Create a copy of the example env file and modify the necessary settings in the .env file:
    ```console
    cp .env.example .env
    ```
    - **EXPOSE_HOST** should be set to the expose server you want to connect to
    - **APP_SUBDOMAIN** should be modified by replacing `-xx` in `woocommerce-dev-xx` with a number for
      example `woocommerce-dev-05`
    - **EXPOSE_TOKEN** must be entered
3. Start the Docker containers
    ```console
    docker-compose up -d
    ```
4. Install and activate WordPress with WooCommerce and the ICEPAY plugin
    ```console
    make install
    ```
