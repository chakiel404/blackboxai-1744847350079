<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit4973df9a69bdd72d4c5b6c7e38c3b63c
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        require __DIR__ . '/platform_check.php';

        spl_autoload_register(array('ComposerAutoloaderInit4973df9a69bdd72d4c5b6c7e38c3b63c', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInit4973df9a69bdd72d4c5b6c7e38c3b63c', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInit4973df9a69bdd72d4c5b6c7e38c3b63c::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
