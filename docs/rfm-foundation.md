# Free RFM foundation

The Customers list and Customer Profile show three raw commerce facts. R is the number of whole site-calendar days since `last_order_date` for a customer with at least one recognized order; F is lifetime `total_orders`; M is lifetime net `total_spent` displayed under the existing currency policy. There is no combined score or lookback window.

The optional Customers thresholds are `rfm_recency_max_days` (0–3650), `rfm_frequency_min` (0–100000), and `rfm_monetary_min` (0–99999999999999.999999 with up to six decimals, matching `decimal(20,6)`). They combine with AND inside `YoOhw_COS_Customer_Query`, including when search, other filters, Saved Views, paging, or CSV export are active. Invalid active thresholds match no customers.

The monetary threshold includes only customers with complete currency backfill, comparable money, current commerce metrics version, and persisted `money_currency` equal to the current store currency. R and F remain available independently of monetary readiness. Existing Saved Views without RFM keys continue with empty RFM thresholds; new views save canonical literal thresholds and remain live query definitions.
