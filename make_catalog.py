from pathlib import Path
cats = [
('Vehicles','vehicles','car'),('Motorcycles & Scooters','motorcycles-scooters','bike'),('Vehicle Spare Parts','vehicle-spare-parts','settings'),('Phones & Tablets','phones-tablets','phone'),('Computers & Laptops','computers-laptops','laptop'),('Computer Accessories','computer-accessories','keyboard'),('TV & Home Entertainment','tv-home-entertainment','tv'),('Cameras & Photography','cameras-photography','camera'),('Audio & Speakers','audio-speakers','headphones'),('Gaming','gaming','gamepad'),('Appliances','appliances','plug'),('Generators & Power','generators-power','zap'),('Solar & Renewable Energy','solar-renewable-energy','sun'),('Fashion - Men','fashion-men','shirt'),('Fashion - Women','fashion-women','shirt'),('Fashion - Kids','fashion-kids','shirt'),('Shoes','shoes','footprints'),('Bags & Luggage','bags-luggage','briefcase'),('Jewelry & Watches','jewelry-watches','watch'),('Beauty & Personal Care','beauty-personal-care','sparkles'),('Baby & Kids','baby-kids','baby'),('Health & Wellness','health-wellness','heart'),('Furniture','furniture','sofa'),('Home Decor','home-decor','home'),('Kitchen & Dining','kitchen-dining','utensils'),('Home Appliances','home-appliances','house'),('Garden & Outdoor','garden-outdoor','leaf'),('Building Materials','building-materials','bricks'),('Tools & Hardware','tools-hardware','wrench'),('Plumbing','plumbing','droplets'),('Electrical Materials','electrical-materials','bolt'),('Paint & Finishing','paint-finishing','paintbrush'),('Doors Windows & Roofing','doors-windows-roofing','home'),('Land & Plots','land-plots','map'),('Houses','houses','house'),('Apartments','apartments','building'),('Commercial Property','commercial-property','building-2'),('Office & Business Equipment','office-business-equipment','briefcase'),('Agriculture','agriculture','sprout'),('Livestock','livestock','cow'),('Animal Feed','animal-feed','wheat'),('Seeds & Seedlings','seeds-seedlings','sprout'),('Farm Tools & Equipment','farm-tools-equipment','tractor'),('Fruits & Vegetables','fruits-vegetables','apple'),('Food & Groceries','food-groceries','shopping-basket'),('Drinks & Beverages','drinks-beverages','cup-soda'),('Restaurants & Catering','restaurants-catering','utensils'),('Office Supplies','office-supplies','folder'),('Books & Stationery','books-stationery','book'),('School Supplies','school-supplies','graduation-cap'),('Sports & Fitness','sports-fitness','dumbbell'),('Music Instruments','music-instruments','music'),('Toys & Games','toys-games','puzzle'),('Pet Supplies','pet-supplies','paw'),('Event Equipment','event-equipment','tent'),('Wedding Supplies','wedding-supplies','heart'),('Photography & Media Services','photography-media-services','camera'),('Transport Services','transport-services','truck'),('Delivery & Logistics','delivery-logistics','package'),('Cleaning Services','cleaning-services','sparkles'),('Repair Services','repair-services','wrench'),('Construction Services','construction-services','hard-hat'),('Professional Services','professional-services','briefcase'),('Education & Training','education-training','graduation-cap'),('IT & Digital Services','it-digital-services','code'),('Marketing & Creative Services','marketing-creative-services','megaphone'),('Security Services','security-services','shield'),('Travel & Tourism','travel-tourism','plane'),('Jobs & Opportunities','jobs-opportunities','users'),('Industrial Equipment','industrial-equipment','factory'),('Business & Commercial','business-commercial','store'),('Other Products','other-products','box')]
subs = {
'vehicles':['Cars','SUVs','Pickup Trucks','Trucks','Buses','Vans','Electric Cars','Car Accessories','Car Audio','Car Care','Commercial Vehicles'],
'motorcycles-scooters':['Motorcycles','Scooters','Electric Motorcycles','Motorcycle Helmets','Motorcycle Accessories'],
'vehicle-spare-parts':['Engine Parts','Body Parts','Tyres','Batteries','Brakes','Filters','Lights','Lubricants','Suspension Parts','Electrical Parts'],
'phones-tablets':['Smartphones','Feature Phones','iPhones','Android Phones','Tablets','Smart Watches','Phone Cases','Chargers','Power Banks','Screen Protectors'],
'computers-laptops':['Laptops','Desktops','All-in-One PCs','MacBooks','Monitors','Servers','Mini PCs','Workstations'],
'computer-accessories':['Keyboards','Mice','Printers','Scanners','Webcams','USB Devices','Hard Drives','SSDs','RAM','Routers','Networking'],
'tv-home-entertainment':['Televisions','Smart TVs','Projectors','Streaming Devices','DVD Players','TV Mounts'],
'cameras-photography':['Digital Cameras','DSLR Cameras','Mirrorless Cameras','Lenses','Tripods','Camera Bags','Drones','Lighting'],
'audio-speakers':['Bluetooth Speakers','Home Theater','Soundbars','Headphones','Earbuds','Microphones','Amplifiers','Mixers'],
'gaming':['PlayStation','Xbox','Nintendo','Gaming PCs','Gaming Monitors','Controllers','Gaming Chairs','Gaming Accessories'],
'appliances':['Refrigerators','Freezers','Washing Machines','Cookers','Microwaves','Blenders','Irons','Vacuum Cleaners'],
'generators-power':['Generators','Inverters','Batteries','UPS','Voltage Stabilizers','Power Cables'],
'solar-renewable-energy':['Solar Panels','Solar Batteries','Solar Inverters','Solar Lights','Charge Controllers','Solar Water Heaters'],
'fashion-men':['Shirts','T-Shirts','Trousers','Suits','Jackets','Underwear','Traditional Wear','Accessories'],
'fashion-women':['Dresses','Tops','Skirts','Trousers','Jumpsuits','Traditional Wear','Handbags','Accessories'],
'fashion-kids':['Boys Clothing','Girls Clothing','Baby Clothing','School Uniforms','Kids Shoes','Kids Accessories'],
'shoes':['Men Shoes','Women Shoes','Kids Shoes','Sneakers','Boots','Sandals','Formal Shoes','Sports Shoes'],
'bags-luggage':['Backpacks','Handbags','Travel Bags','Suitcases','Laptop Bags','School Bags','Wallets'],
'jewelry-watches':['Watches','Rings','Necklaces','Bracelets','Earrings','Wedding Jewelry','Smart Watches'],
'beauty-personal-care':['Skincare','Hair Care','Makeup','Perfumes','Body Care','Men Grooming','Salon Equipment','Personal Care Devices'],
'baby-kids':['Baby Furniture','Baby Feeding','Baby Strollers','Car Seats','Diapers','Baby Care','Kids Accessories'],
'health-wellness':['Fitness Equipment','Medical Equipment','Wellness Products','Mobility Aids','Massage Equipment'],
'furniture':['Sofas','Beds','Mattresses','Wardrobes','Tables','Chairs','Desks','Shelves','Office Furniture'],
'home-decor':['Curtains','Rugs','Carpets','Wall Art','Mirrors','Lighting Decor','Cushions','Clocks'],
'kitchen-dining':['Cookware','Dinner Sets','Cutlery','Kitchen Tools','Storage Containers','Dining Tables','Water Dispensers'],
'home-appliances':['Fans','Air Conditioners','Water Heaters','Kitchen Appliances','Cleaning Appliances'],
'garden-outdoor':['Garden Tools','Plants','Outdoor Furniture','BBQ Equipment','Camping Equipment','Outdoor Lighting'],
'building-materials':['Cement','Bricks','Blocks','Sand','Gravel','Steel','Timber','Tiles','Gypsum','Insulation'],
'tools-hardware':['Hand Tools','Power Tools','Drills','Grinders','Welding Equipment','Ladders','Fasteners','Locks'],
'plumbing':['Pipes','Fittings','Taps','Showers','Toilets','Water Tanks','Pumps','Drainage'],
'electrical-materials':['Cables','Switches','Sockets','Breakers','Distribution Boards','Bulbs','Electrical Tools'],
'paint-finishing':['Wall Paint','Wood Paint','Metal Paint','Primers','Varnish','Brushes','Rollers','Wallpaper'],
'doors-windows-roofing':['Doors','Windows','Roofing Sheets','Roof Tiles','Gutters','Ceiling Materials','Door Hardware'],
'land-plots':['Residential Land','Commercial Land','Agricultural Land','Plots','Farm Land'],
'houses':['Houses for Sale','Houses for Rent','Villas','Family Homes','Student Housing'],
'apartments':['Apartments for Sale','Apartments for Rent','Furnished Apartments','Studio Apartments','Luxury Apartments'],
'commercial-property':['Shops','Offices','Warehouses','Hotels','Restaurants','Commercial Buildings'],
'office-business-equipment':['Office Desks','Office Chairs','Printers','Photocopiers','Safes','POS Equipment','Shredders'],
'agriculture':['Farm Produce','Farm Equipment','Irrigation','Greenhouse Equipment','Fertilizers','Pesticides','Farm Tools'],
'livestock':['Cattle','Goats','Sheep','Pigs','Chickens','Rabbits','Fish','Bees','Dogs','Cats'],
'animal-feed':['Cattle Feed','Poultry Feed','Pig Feed','Goat Feed','Fish Feed','Mineral Supplements'],
'seeds-seedlings':['Maize Seeds','Beans Seeds','Vegetable Seeds','Fruit Seedlings','Tree Seedlings','Flower Seeds'],
'farm-tools-equipment':['Tractors','Ploughs','Sprayers','Irrigation Equipment','Harvesting Tools','Hand Tools','Animal Equipment'],
'fruits-vegetables':['Fresh Fruits','Vegetables','Potatoes','Tomatoes','Onions','Bananas','Avocados'],
'food-groceries':['Rice','Flour','Sugar','Cooking Oil','Beans','Pasta','Canned Foods','Spices','Snacks'],
'drinks-beverages':['Water','Juices','Soft Drinks','Coffee','Tea','Energy Drinks'],
'restaurants-catering':['Catering','Restaurant Services','Wedding Catering','Corporate Catering','Bakery','Fast Food'],
'office-supplies':['Paper','Pens','Files','Printer Supplies','Desk Accessories','Office Storage'],
'books-stationery':['Books','Notebooks','Pens','Art Supplies','Calculators','Calendars','Bibles & Religious Books'],
'school-supplies':['School Bags','Uniforms','Textbooks','Stationery','Shoes','Learning Materials'],
'sports-fitness':['Gym Equipment','Football','Basketball','Volleyball','Running','Cycling','Boxing','Fitness Wear'],
'music-instruments':['Guitars','Pianos','Keyboards','Drums','Violins','Brass Instruments','DJ Equipment'],
'toys-games':['Educational Toys','Outdoor Toys','Board Games','Video Games','Dolls','Remote Control Toys'],
'pet-supplies':['Dog Supplies','Cat Supplies','Bird Supplies','Aquarium Supplies','Pet Food','Pet Grooming'],
'event-equipment':['Tents','Chairs','Tables','Sound Systems','Lighting','Decor','Generators','Stage Equipment'],
'wedding-supplies':['Wedding Decor','Wedding Dresses','Suits','Flowers','Wedding Chairs','Wedding Cakes','Invitations'],
'photography-media-services':['Photography','Videography','Editing','Livestreaming','Photo Booth','Drone Services'],
'transport-services':['Car Hire','Taxi Services','Bus Hire','Motorcycle Transport','Moving Services'],
'delivery-logistics':['Courier','Parcel Delivery','Moving Logistics','Freight','Warehouse Services'],
'cleaning-services':['Home Cleaning','Office Cleaning','Deep Cleaning','Laundry','Car Cleaning','Fumigation'],
'repair-services':['Phone Repair','Computer Repair','Appliance Repair','Vehicle Repair','Shoe Repair','Furniture Repair'],
'construction-services':['Masonry','Carpentry','Roofing','Plumbing Services','Electrical Services','Painting','Tiling'],
'professional-services':['Accounting','Legal Services','Consulting','Architecture','Engineering','HR Services'],
'education-training':['Tutoring','Language Training','Computer Training','Vocational Training','Online Courses'],
'it-digital-services':['Web Development','Mobile Apps','Graphic Design','IT Support','Cloud Services','Cybersecurity'],
'marketing-creative-services':['Digital Marketing','Social Media','Branding','Printing','Content Creation','Advertising'],
'security-services':['Security Guards','CCTV Installation','Alarm Systems','Access Control','Security Equipment'],
'travel-tourism':['Hotels','Tour Packages','Car Rental','Tour Guides','Airport Transfers','Travel Services'],
'jobs-opportunities':['Full Time Jobs','Part Time Jobs','Internships','Freelance','Domestic Work','Skilled Jobs'],
'industrial-equipment':['Industrial Machines','Compressors','Generators','Pumps','Welding Machines','Factory Equipment'],
'business-commercial':['Shop Equipment','POS Systems','Restaurant Equipment','Salon Equipment','Retail Displays','Wholesale Goods'],
'other-products':['Miscellaneous','Collectibles','Handmade Products','Second Hand','Other']}
lines=['-- ISOKO RYACU — Mega Marketplace Category Catalog (70+ categories / 500+ subcategories)','-- Safe/idempotent: does not drop existing data. Import after schema/phase migrations.','SET NAMES utf8mb4;','']
for i,(name,slug,icon) in enumerate(cats,1):
    nk='cat.'+slug.replace('-','_')
    esc=lambda s:s.replace("'","''")
    lines.append(f"INSERT INTO categories (name, slug, name_key, icon, description, parent_id, display_order, is_active) VALUES ('{esc(name)}','{slug}','{nk}','{icon}','Marketplace category: {esc(name)}',NULL,{i},1) ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key), icon=VALUES(icon), description=VALUES(description), parent_id=NULL, display_order=VALUES(display_order), is_active=1;")
lines.append('')
for name,slug,icon in cats:
    arr=subs.get(slug,[name])
    lines.append(f'-- {name}')
    for j,s in enumerate(arr,1):
        ss=s.lower().replace('&','and').replace('/','-').replace(' ','-').replace("'",'').replace('--','-')
        # normalize
        import re
        ss=re.sub(r'[^a-z0-9-]+','-',ss).strip('-')
        nk='subcat.'+ss
        esc=lambda x:x.replace("'","''")
        lines.append(f"INSERT INTO subcategories (category_id, name, slug, name_key) SELECT id, '{esc(s)}', '{ss}', '{nk}' FROM categories WHERE slug='{slug}' ON DUPLICATE KEY UPDATE name=VALUES(name), name_key=VALUES(name_key);")
    lines.append('')
Path('/tmp/msfix/sql/category_catalog_mega_upgrade.sql').write_text('\n'.join(lines),encoding='utf-8')
print(len(cats), sum(len(subs.get(s,[n])) for n,s,_ in cats))
