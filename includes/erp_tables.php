<?php
// Definición de tablas y campos relevantes del ERP AGC para WooCommerce4AGC
// Puedes ampliar este array con más tablas según lo necesites

return [
    'products' => [
        'table' => 31, // ID de la tabla de productos en el ERP
        'fields' => [0, 1, 2, 3, 4, 37, 38, 40, 42, 46, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 74, 75, 78],
        'fields_map' => [
            0  => 'id',                 // ID interno
            1  => 'sku',                // SKU
            2  => 'name',               // Nombre
            3  => 'description',        // Descripción
            4  => 'price_euros',        // Precio en euros, no usar de momento
            37 => 'product_weight',     // Peso del producto en gramos
            38 => 'pages',              // Numero de paginas producto fisico
            40 => 'category_primary',   // categoria principal
            42 => 'manage_stock',       // Gestionar stock, el valor 98 es para productos digitales, para el resto no está bien definido
            46 => 'parent_sku',         // SKU padre (solo para variaciones, product_type=200)
            59 => 'product_include',    // Lo que el producto incluye
            60 => 'product_description',// Descripción del producto
            61 => 'product_level',      // Nivel del producto
            62 => 'product_title',      // Titulo del producto
            63 => 'product_authors',    // Autores del producto
            64 => 'product_finished',   // Tipo de encuadernacion para productos fisicos
            65 => 'product_to',         // Alumnos a los que va dirigido el producto
            66 => 'product_year',       // Año de publicación del producto
            67 => 'product_collection', // Colección del producto
            68 => 'product_dimensions', // Dimensiones del producto, no usar de momento
            69 => 'product_legend',     // Leyendas del producto
            74 => 'license_duration',   // Duración de la licencia
            75 => 'price_usd',          // Precio en dólares
            78 => 'product_type',       // 100 = producto principal, 200 = variación
        ],
        'search_field' => 1, // Campo para búsquedas por SKU
        'description' => 'Tabla de productos: contiene los productos del ERP AGC con sus campos principales. "parent_sku" solo aplica a variaciones (product_type=200). "product_type" define si es producto principal (100) o variación (200).'
    ],
    // Puedes añadir aquí más tablas, por ejemplo 'stock', 'prices', etc.
]; 