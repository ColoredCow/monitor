## Installation Guidelines :rocket:

Before you start following the guidelines, make sure to go through the [prerequisites guide](./prerequisites.md) to install the required tools and packages on your machine.

**Note:** If you are a Windows user, use GitBash or PowerShell instead of the command prompt.

1. Navigate to the right directory where your project will be locally saved
    - For WAMP:
        ```sh
        cd C:\wamp64\www\
        ```
    - For XAMPP:
        ```sh
        cd C:\xampp\htdocs\
        ```
    - For MAMP(macOS):
        ```sh
        cd /Application/MAMP/htdocs/
    ```
    Note- phpMyAdmin/PHP, apache, and MySQL comes preinstalled with the  WAMP, XAMPP package, that is going to be used later on in this project, so no need to install these files separately. 

2. Clone this repository and move to `monitor` directory
   ```sh
   git clone https://github.com/ColoredCow/monitor.git
   cd monitor
   ```

3. Install Composer
   ```sh
   composer install
   ```
   A possible error may arise with `composer install`. So try running the following command.
      ```
       composer update
      ```
 
    - After running `composer update` then run  "composer install --ignore-platform-req=ext-intl" command as some packages were creating problem so we ignore it by using this command. 
         ```
          composer install --ignore-platform-req=ext-intl
         ```
4. Run npm install
     ```sh
     npm install
     ```
5. npm build
   ```sh
   npm run dev
   ```
    A possible error may arise with `cross-env`. So try running the following commands.
    - To clear a cache in npm, we need to run the npm cache command in our terminal and install cross-env.
   ```sh
   npm cache clear --force
   npm install cross-env

   npm install
   npm run dev
   ```


6. Make a copy of the `.env.example` file in the same directory and save it as `.env`:
     ```sh
    cp .env.example .env
    ```

7. Run the following command to add the Laravel application key:
   ```sh
   php artisan key:generate
   ```
   **Note:** Make sure that the 'php.ini' file in XAMPP/WAMP has this code uncommented/written
    `extension=gd`


8. Add the following settings in `.env` file:
    1. Laravel app configurations
    ```sh
    APP_NAME=Laravel
    APP_ENV=local
    APP_DEBUG=true
    APP_URL=localhost
    ```

    2. Database configurations
    - Create a database in your local server. Check out [this link](https://www.youtube.com/watch?v=k9yJR_ZJbvI&ab_channel=1BestCsharpblog) and skip to 0:21.
    - Configure your Laravel app with the right DB settings. Check out [this link](https://www.youtube.com/watch?v=4geOENi3--M). Relevant parts are 2:00-2:42 and 4:20-5:40.
    - Read [the story](https://docs.google.com/document/d/1sWj0F2uXkSE9oHBkChv-yC2L7P7qazsPY5sNPC1PIp4/edit) about how the team discussed which video should be in the docs

    ```sh
    DB_CONNECTION=mysql
    DB_HOST=localhost
    DB_PORT=3306
    DB_DATABASE=laravel
    DB_USERNAME=root
    DB_PASSWORD=
    ```
    **Note:** Use the default values for MySQL database in `.env` file
    ```
    DB_USERNAME=root
    DB_PASSWORD=
    ```

    These credentials will be used when you will connect to MySQL Server whether you use XAMPP, WAMP, MAMP (PhpMyadmin) or TablePlus, the proper steps you can find here in the [prerequisites guide](./docs/prerequisites.md).

    3. _(Optional)_ Google configurations.
    ```sh
    GOOGLE_CLIENT_ID=
    GOOGLE_CLIENT_SECRET=
    GOOGLE_CLIENT_CALLBACK=
    GOOGLE_CLIENT_HD=
    GOOGLE_API_KEY=
    GOOGLE_APPLICATION_CREDENTIALS=
    GOOGLE_SERVICE_ACCOUNT_IMPERSONATE=
    ```

    4. _(Optional)_ ColoredCow website Configurations
    In case you want to use website integration functionality, then you need to enable `WORDPRESS_ENABLED` as `true` and add wordpress database configurations.

    ```sh
    DB_WORDPRESS_DATABASE=
    DB_WORDPRESS_USERNAME=
    DB_WORDPRESS_PASSWORD=
    DB_WORDPRESS_PREFIX=
    WORDPRESS_ENABLED=true
    ```

9. Run migrations
    ```sh
    php artisan migrate
    ```
10. Start development server
   
    ```sh
    php artisan serve
    ``` 
    Note- php artisan serve command will start a development server on your local machine that listens to port 8000 by default. This command will provide a link in the terminal which we can copy and paste it in our browser and see our laraver application. 
