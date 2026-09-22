#!/bin/sh
# Seed the STAGING database for docker/scripts/staging_phase0_tests.sh. Synthetic data only.
set -eu
DB=${STAGING_DB:-monkeybot-staging-db-1}
APP=${STAGING_APP:-monkeybot-staging-app-1}
H=$(docker exec "$APP" php -r 'echo password_hash("Staging#Pass1", PASSWORD_DEFAULT);')
docker exec -i "$DB" sh -c 'mariadb -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' <<SQL
UPDATE users SET email='admin@staging.local', password='$H', status='1', deleted='0', expired_date='2030-01-01' WHERE id=1;
INSERT INTO package (id, package_name, module_ids, monthly_limit, bulk_limit, price, validity, is_default, deleted, visible)
  VALUES (90,'KB only','342','{}','{}','0','3650','0','0','1') ON DUPLICATE KEY UPDATE module_ids='342';
INSERT IGNORE INTO users (id,name,email,fb_id,mobile,password,address,user_type,status,purchase_date,last_login_at,expired_date,package_id,deleted,paypal_email,last_payment_method,last_login_ip,affiliate_id) VALUES
  (11,'Tenant A','a@staging.local','','','$H','','Member','1',NOW(),NOW(),'2030-01-01',90,'0','','','',0),
  (12,'Tenant B','b@staging.local','','','$H','','Member','1',NOW(),NOW(),'2030-01-01',90,'0','','','',0);
INSERT IGNORE INTO ai_knowledge_sources (id,user_id,source_name,source_type,status,total_chunks,created_at,updated_at)
  VALUES (501,11,'Tenant A secret price list','text','active',1,NOW(),NOW());
INSERT INTO ai_knowledge_chunks (source_id,chunk_text,chunk_order,created_at)
  SELECT 501,'TENANT-A-CONFIDENTIAL: suite 9999 EGP',0,NOW() FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM ai_knowledge_chunks WHERE source_id=501);
INSERT INTO facebook_rx_config (id,app_name,api_id,api_secret,numeric_id,user_id,facebook_id,secret_code,deleted,status)
  VALUES (1,'staging','111','staging-app-secret','0',1,'','','0','1')
  ON DUPLICATE KEY UPDATE api_secret='staging-app-secret', deleted='0';
SQL
echo "staging seeded"
