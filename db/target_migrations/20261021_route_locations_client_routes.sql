-- Routes keep one row per origin. Each route carries pickup/delivery location
-- options (route_locations), and routes are linked to the clients that use them
-- (client_routes). routes.destination stays as the default delivery location so
-- existing reports and bookings keep working.

CREATE TABLE IF NOT EXISTS route_locations (
    route_location_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_id INT UNSIGNED NOT NULL,
    pickup_location VARCHAR(255) NOT NULL,
    delivery_location VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_route_locations_pair (route_id, pickup_location, delivery_location),
    CONSTRAINT fk_route_locations_route FOREIGN KEY (route_id) REFERENCES routes(route_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_routes (
    client_id INT UNSIGNED NOT NULL,
    route_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, route_id),
    KEY idx_client_routes_route (route_id),
    CONSTRAINT fk_client_routes_client FOREIGN KEY (client_id) REFERENCES clients(client_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_client_routes_route FOREIGN KEY (route_id) REFERENCES routes(route_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Existing routes: origin is the pickup, destination the delivery location.
INSERT IGNORE INTO route_locations (route_id, pickup_location, delivery_location)
SELECT route_id, origin, destination FROM routes;

-- Clients that already have bookings on a route are linked to it.
INSERT IGNORE INTO client_routes (client_id, route_id)
SELECT DISTINCT client_id, route_id FROM client_bookings
WHERE client_id IS NOT NULL AND route_id IS NOT NULL;
