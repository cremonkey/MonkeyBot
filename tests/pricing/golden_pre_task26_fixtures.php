<?php
/**
 * Golden fixtures captured from the PRE-task-26 pricing_helper.php (commit
 * 81d76070^) via pricing_tool_definition()/pricing_prompt_rule() for BYOOT,
 * a catalog-only config (SALON) and BYOOT+catalog (SHOP). Used by run.php to
 * assert the byte-identical gate on these two functions: a config that does
 * not use any new field (coupons/seasons/weekend/packages) must still get
 * exactly this shape from the current code.
 */
$GOLDEN_BYOOT_DEF = array (
  'name' => 'calculate_price',
  'description' => 'Compute an EXACT price total for accommodation or day-use. ALWAYS call this instead of doing the math yourself whenever the customer gives enough to price a stay: for accommodation pass nights + how many adults and children (by age band); for day_use pass the package and how many adults and children. It applies the real offers (multi-night discount, buy-N-get-one-free), picks a room that fits the headcount, and defers to the team for anything it cannot price. Quote its total verbatim.',
  'params' => 
  array (
    'type' => 'object',
    'properties' => 
    array (
      'type' => 
      array (
        'type' => 'string',
        'enum' => 
        array (
          0 => 'accommodation',
          1 => 'day_use',
        ),
        'description' => 'accommodation = overnight stay; day_use = day visit without staying.',
      ),
      'room_or_package' => 
      array (
        'type' => 'string',
        'description' => 'For accommodation: the room name if the customer named one (else leave empty and it picks the fitting room). For day_use: the package name if named.',
      ),
      'nights' => 
      array (
        'type' => 'integer',
        'description' => 'Accommodation only: number of nights.',
      ),
      'adults' => 
      array (
        'type' => 'integer',
        'description' => 'Number of adults (12+ counts as adult).',
      ),
      'children_under_5' => 
      array (
        'type' => 'integer',
        'description' => 'Children up to 5 years (usually free).',
      ),
      'children_6_to_11' => 
      array (
        'type' => 'integer',
        'description' => 'Children from 6 to under 12.',
      ),
      'children_12_plus' => 
      array (
        'type' => 'integer',
        'description' => 'Children 12 or older (billed as full adults).',
      ),
    ),
    'required' => 
    array (
      0 => 'type',
    ),
  ),
);
$GOLDEN_SALON_DEF = array (
  'name' => 'calculate_price',
  'description' => 'Compute an EXACT price from this page\'s price list. ALWAYS call this instead of stating or multiplying a price yourself: for item pass the item name + variant (if named) + quantity. It resolves the item name (Arabic nicknames too), applies the real prices, and defers to the team for anything it cannot price (price on request, unclear item). If the name matches several items it tells you to ask which one. Quote its total verbatim.',
  'params' => 
  array (
    'type' => 'object',
    'properties' => 
    array (
      'type' => 
      array (
        'type' => 'string',
        'enum' => 
        array (
          0 => 'item',
        ),
        'description' => 'item = a product or service from the price list.',
      ),
      'item' => 
      array (
        'type' => 'string',
        'description' => 'type item: the product/service the customer asked about, in their own words (Arabic or English).',
      ),
      'variant' => 
      array (
        'type' => 'string',
        'description' => 'type item: the option/size/variant if the customer named one, else leave empty.',
      ),
      'quantity' => 
      array (
        'type' => 'number',
        'description' => 'type item: how many (pieces, hours, sessions, kg...). Default 1.',
      ),
    ),
    'required' => 
    array (
      0 => 'type',
    ),
  ),
);
$GOLDEN_BYOOTPLUS_DEF = array (
  'name' => 'calculate_price',
  'description' => 'Compute an EXACT price from this page\'s price list. ALWAYS call this instead of stating or multiplying a price yourself: for item pass the item name + variant (if named) + quantity; for accommodation pass nights + how many adults and children (by age band); for day_use pass the package and how many adults and children. It resolves the item name (Arabic nicknames too), applies the real prices and offers, and defers to the team for anything it cannot price (price on request, unclear item). If the name matches several items it tells you to ask which one. Quote its total verbatim.',
  'params' => 
  array (
    'type' => 'object',
    'properties' => 
    array (
      'type' => 
      array (
        'type' => 'string',
        'enum' => 
        array (
          0 => 'accommodation',
          1 => 'day_use',
          2 => 'item',
        ),
        'description' => 'accommodation = overnight stay; day_use = day visit without staying; item = a product or service from the price list.',
      ),
      'item' => 
      array (
        'type' => 'string',
        'description' => 'type item: the product/service the customer asked about, in their own words (Arabic or English).',
      ),
      'variant' => 
      array (
        'type' => 'string',
        'description' => 'type item: the option/size/variant if the customer named one, else leave empty.',
      ),
      'quantity' => 
      array (
        'type' => 'number',
        'description' => 'type item: how many (pieces, hours, sessions, kg...). Default 1.',
      ),
      'room_or_package' => 
      array (
        'type' => 'string',
        'description' => 'For accommodation: the room name if the customer named one (else leave empty and it picks the fitting room). For day_use: the package name if named.',
      ),
      'nights' => 
      array (
        'type' => 'integer',
        'description' => 'Accommodation only: number of nights.',
      ),
      'adults' => 
      array (
        'type' => 'integer',
        'description' => 'Number of adults (12+ counts as adult).',
      ),
      'children_under_5' => 
      array (
        'type' => 'integer',
        'description' => 'Children up to 5 years (usually free).',
      ),
      'children_6_to_11' => 
      array (
        'type' => 'integer',
        'description' => 'Children from 6 to under 12.',
      ),
      'children_12_plus' => 
      array (
        'type' => 'integer',
        'description' => 'Children 12 or older (billed as full adults).',
      ),
    ),
    'required' => 
    array (
      0 => 'type',
    ),
  ),
);
$GOLDEN_BYOOT_RULE = '
10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer gives a COUNT you can price: a number of nights, OR a number of people/adults, OR a headcount for day-use. \'داي يوز لـ5 أفراد\', \'اقامة ليلتين\', \'4 كبار وطفلين\' all mean CALL THE TOOL now with what you have - do not ask more questions first if you already have a count, and never answer such a question with \'بيبدأ من\' when the customer gave you a specific number. Quote the tool\'s total exactly. If it returns NO FINAL TOTAL, tell the customer the team will confirm and ask for their WhatsApp - do not invent a total.';
$GOLDEN_SALON_RULE = '
10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer asks a price or gives a COUNT you can price: a quantity of a product or service from the price list (type item: the item in the customer\'s words, the option/size if they said one, and the quantity). \'عايز 3 من ده\', \'جلستين\', \'بكام الواحد\' all mean CALL THE TOOL now with what you have - do not ask more questions first if you already have a count, and never answer such a question with \'بيبدأ من\' when the customer gave you a specific number. Quote the tool\'s total exactly. If it returns NO FINAL TOTAL, tell the customer the team will confirm and ask for their WhatsApp - do not invent a total. If the tool says the name matches several items, ask the customer which one they mean - never pick one yourself.';
$GOLDEN_BYOOTPLUS_RULE = '
10. NEVER do price arithmetic yourself. Call the calculate_price tool whenever the customer asks a price or gives a COUNT you can price: a number of nights for a stay, OR a headcount for day-use, OR a quantity of a product or service from the price list (type item: the item in the customer\'s words, the option/size if they said one, and the quantity). \'اقامة ليلتين\', \'4 كبار وطفلين\', \'داي يوز لـ5 أفراد\', \'عايز 3 من ده\', \'جلستين\', \'بكام الواحد\' all mean CALL THE TOOL now with what you have - do not ask more questions first if you already have a count, and never answer such a question with \'بيبدأ من\' when the customer gave you a specific number. Quote the tool\'s total exactly. If it returns NO FINAL TOTAL, tell the customer the team will confirm and ask for their WhatsApp - do not invent a total. If the tool says the name matches several items, ask the customer which one they mean - never pick one yourself.';