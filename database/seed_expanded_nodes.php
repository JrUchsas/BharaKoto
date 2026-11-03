<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

echo "[-] Seeding comprehensive Dhaka locations into popular_nodes...\n";

$nodes = [
    // Mirpur & Surrounds
    ['name_en' => 'Mirpur 10 Gol Chakkar', 'name_bn' => 'মিরপুর ১০ গোল চক্কর', 'area_thana' => 'Mirpur', 'lat' => 23.8069, 'lng' => 90.3687, 'type' => 'intersection'],
    ['name_en' => 'Mirpur 1 Muktijoddha Market', 'name_bn' => 'মিরপুর ১ মুক্তিযোদ্ধা মার্কেট', 'area_thana' => 'Mirpur', 'lat' => 23.7956, 'lng' => 90.3537, 'type' => 'intersection'],
    ['name_en' => 'Mirpur 2 National Stadium', 'name_bn' => 'মিরপুর ২ জাতীয় স্টেডিয়াম', 'area_thana' => 'Mirpur', 'lat' => 23.8041, 'lng' => 90.3625, 'type' => 'general'],
    ['name_en' => 'Mirpur 11 Bus Stand', 'name_bn' => 'মিরপুর ১১ বাস স্ট্যান্ড', 'area_thana' => 'Pallabi', 'lat' => 23.8183, 'lng' => 90.3653, 'type' => 'bus_stand'],
    ['name_en' => 'Mirpur 12 Bus Stand', 'name_bn' => 'মিরপুর ১২ বাস স্ট্যান্ড', 'area_thana' => 'Pallabi', 'lat' => 23.8277, 'lng' => 90.3654, 'type' => 'bus_stand'],
    ['name_en' => 'Mirpur 14 Police Staff College', 'name_bn' => 'মিরপুর ১৪ মোড়', 'area_thana' => 'Kafrul', 'lat' => 23.7997, 'lng' => 90.3845, 'type' => 'intersection'],
    ['name_en' => 'Kazipara Metro Station', 'name_bn' => 'কাজীপুর মেট্রো স্টেশন', 'area_thana' => 'Mirpur', 'lat' => 23.7924, 'lng' => 90.3729, 'type' => 'metro_station'],
    ['name_en' => 'Shewrapara Metro Station', 'name_bn' => 'শেওড়াপাড়া মেট্রো স্টেশন', 'area_thana' => 'Mirpur', 'lat' => 23.7876, 'lng' => 90.3748, 'type' => 'metro_station'],
    ['name_en' => 'Pallabi Metro Station', 'name_bn' => 'পল্লবী মেট্রো স্টেশন', 'area_thana' => 'Pallabi', 'lat' => 23.8245, 'lng' => 90.3644, 'type' => 'metro_station'],
    ['name_en' => 'Rupnagar Residential Area', 'name_bn' => 'রূপনগর আবাসিক এলাকা', 'area_thana' => 'Rupnagar', 'lat' => 23.8166, 'lng' => 90.3541, 'type' => 'general'],

    // Agargaon, Farmgate, Tejgaon & Karwan Bazar
    ['name_en' => 'Agargaon Metro Station', 'name_bn' => 'আগারগাঁও মেট্রো স্টেশন', 'area_thana' => 'Sher-e-Bangla Nagar', 'lat' => 23.7784, 'lng' => 90.3802, 'type' => 'metro_station'],
    ['name_en' => 'Farmgate Khamarbari', 'name_bn' => 'ফার্মগেট খামারবাড়ি', 'area_thana' => 'Tejgaon', 'lat' => 23.7574, 'lng' => 90.3899, 'type' => 'intersection'],
    ['name_en' => 'Farmgate Ananda Cinema', 'name_bn' => 'ফার্মগেট আনন্দ সিনেমা', 'area_thana' => 'Tejgaon', 'lat' => 23.7588, 'lng' => 90.3908, 'type' => 'intersection'],
    ['name_en' => 'Karwan Bazar Metro Station', 'name_bn' => 'কারওয়ান বাজার', 'area_thana' => 'Tejgaon', 'lat' => 23.7505, 'lng' => 90.3934, 'type' => 'metro_station'],
    ['name_en' => 'Tejgaon Nabisco Mor', 'name_bn' => 'তেজগাঁও নাবিস্কো মোড়', 'area_thana' => 'Tejgaon Industrial', 'lat' => 23.7689, 'lng' => 90.4022, 'type' => 'intersection'],
    ['name_en' => 'Tejgaon Saheed Minar', 'name_bn' => 'তেজগাঁও শহীদ মিনার', 'area_thana' => 'Tejgaon', 'lat' => 23.7612, 'lng' => 90.3985, 'type' => 'general'],
    ['name_en' => 'Bijoy Sarani Metro Station', 'name_bn' => 'বিজয় সরণি মেট্রো স্টেশন', 'area_thana' => 'Tejgaon', 'lat' => 23.7656, 'lng' => 90.3877, 'type' => 'metro_station'],
    ['name_en' => 'Hatirjheel Madhubagh Point', 'name_bn' => 'হাতিরঝিল মধুবাগ', 'area_thana' => 'Rampura', 'lat' => 23.7595, 'lng' => 90.4102, 'type' => 'general'],

    // Dhanmondi, Kalabagan & Mohammadpur
    ['name_en' => 'Dhanmondi 32', 'name_bn' => 'ধানমন্ডি ৩২', 'area_thana' => 'Dhanmondi', 'lat' => 23.7516, 'lng' => 90.3779, 'type' => 'general'],
    ['name_en' => 'Dhanmondi 27 (Rapa Plaza)', 'name_bn' => 'ধানমন্ডি ২৭ (রাপা প্লাজা)', 'area_thana' => 'Dhanmondi', 'lat' => 23.7554, 'lng' => 90.3725, 'type' => 'intersection'],
    ['name_en' => 'Dhanmondi 8/A (Bridge)', 'name_bn' => 'ধানমন্ডি ৮/এ ব্রিজ', 'area_thana' => 'Dhanmondi', 'lat' => 23.7460, 'lng' => 90.3768, 'type' => 'general'],
    ['name_en' => 'Dhanmondi Lake Amphitheatre', 'name_bn' => 'ধানমন্ডি লেক রবীন্দ্র সরোবর', 'area_thana' => 'Dhanmondi', 'lat' => 23.7465, 'lng' => 90.3798, 'type' => 'general'],
    ['name_en' => 'Science Laboratory Mor', 'name_bn' => 'সায়েন্স ল্যাবরেটরি মোড়', 'area_thana' => 'Dhanmondi', 'lat' => 23.7401, 'lng' => 90.3846, 'type' => 'intersection'],
    ['name_en' => 'Shimanto Square (Rifles Square)', 'name_bn' => 'সীমান্ত স্কয়ার', 'area_thana' => 'Dhanmondi', 'lat' => 23.7397, 'lng' => 90.3758, 'type' => 'general'],
    ['name_en' => 'Kalabagan Bus Stand', 'name_bn' => 'কলাবাগান বাস স্ট্যান্ড', 'area_thana' => 'Kalabagan', 'lat' => 23.7482, 'lng' => 90.3815, 'type' => 'bus_stand'],
    ['name_en' => 'Panthapath Signal (Square Hospital)', 'name_bn' => 'পান্থপথ স্কয়ার হাসপাতাল', 'area_thana' => 'Kalabagan', 'lat' => 23.7523, 'lng' => 90.3845, 'type' => 'hospital'],
    ['name_en' => 'Green Road Mor', 'name_bn' => 'গ্রিন রোড মোড়', 'area_thana' => 'Kalabagan', 'lat' => 23.7471, 'lng' => 90.3862, 'type' => 'intersection'],
    ['name_en' => 'Mohammadpur Town Hall', 'name_bn' => 'মোহাম্মদপুর টাউন হল', 'area_thana' => 'Mohammadpur', 'lat' => 23.7589, 'lng' => 90.3642, 'type' => 'intersection'],
    ['name_en' => 'Mohammadpur Bus Stand', 'name_bn' => 'মোহাম্মদপুর বাস স্ট্যান্ড', 'area_thana' => 'Mohammadpur', 'lat' => 23.7538, 'lng' => 90.3601, 'type' => 'bus_stand'],
    ['name_en' => 'Japan Garden City', 'name_bn' => 'জাপান গার্ডেন সিটি', 'area_thana' => 'Mohammadpur', 'lat' => 23.7692, 'lng' => 90.3571, 'type' => 'general'],
    ['name_en' => 'Asad Gate', 'name_bn' => 'আসাদ গেট', 'area_thana' => 'Mohammadpur', 'lat' => 23.7601, 'lng' => 90.3725, 'type' => 'intersection'],
    ['name_en' => 'Shia Mosque Mor', 'name_bn' => 'শিয়া মসজিদ মোড়', 'area_thana' => 'Mohammadpur', 'lat' => 23.7661, 'lng' => 90.3582, 'type' => 'intersection'],
    ['name_en' => 'Jigatola Bus Stand', 'name_bn' => 'জিগাতলা বাস স্ট্যান্ড', 'area_thana' => 'Hazaribagh', 'lat' => 23.7410, 'lng' => 90.3720, 'type' => 'bus_stand'],

    // Shyamoli, Kalyanpur & Gabtoli
    ['name_en' => 'Shyamoli Cinema / Shishu Mela', 'name_bn' => 'শ্যামলী শিশু মেলা', 'area_thana' => 'Adabor', 'lat' => 23.7719, 'lng' => 90.3662, 'type' => 'intersection'],
    ['name_en' => 'Kalyanpur Bus Stand', 'name_bn' => 'কল্যাণপুর বাস স্ট্যান্ড', 'area_thana' => 'Kalyanpur', 'lat' => 23.7801, 'lng' => 90.3611, 'type' => 'bus_stand'],
    ['name_en' => 'Technical Mor', 'name_bn' => 'টেকনিক্যাল মোড়', 'area_thana' => 'Darussalam', 'lat' => 23.7865, 'lng' => 90.3530, 'type' => 'intersection'],
    ['name_en' => 'Gabtoli Inter-District Bus Terminal', 'name_bn' => 'গাবতলী বাস টার্মিনাল', 'area_thana' => 'Gabtoli', 'lat' => 23.7850, 'lng' => 90.3420, 'type' => 'bus_stand'],

    // Gulshan, Banani, Baridhara & Bashundhara
    ['name_en' => 'Gulshan 1 Circle', 'name_bn' => 'গুলশান ১ চত্বর', 'area_thana' => 'Gulshan', 'lat' => 23.7797, 'lng' => 90.4168, 'type' => 'intersection'],
    ['name_en' => 'Gulshan 2 Circle', 'name_bn' => 'গুলশান ২ চত্বর', 'area_thana' => 'Gulshan', 'lat' => 23.7937, 'lng' => 90.4143, 'type' => 'intersection'],
    ['name_en' => 'Banani Road 11', 'name_bn' => 'বনানী ১১ নম্বর রোড', 'area_thana' => 'Banani', 'lat' => 23.7931, 'lng' => 90.4042, 'type' => 'general'],
    ['name_en' => 'Banani Kamal Ataturk Avenue', 'name_bn' => 'বনানী কামাল আতাতুর্ক এভিনিউ', 'area_thana' => 'Banani', 'lat' => 23.7938, 'lng' => 90.4031, 'type' => 'intersection'],
    ['name_en' => 'Kakoli Bus Stand', 'name_bn' => 'কাকলী বাস স্ট্যান্ড', 'area_thana' => 'Banani', 'lat' => 23.7915, 'lng' => 90.4001, 'type' => 'bus_stand'],
    ['name_en' => 'Baridhara Diplomatic Zone', 'name_bn' => 'বারিধারা ডিপ্লোম্যাটিক জোন', 'area_thana' => 'Baridhara', 'lat' => 23.8012, 'lng' => 90.4201, 'type' => 'general'],
    ['name_en' => 'Notun Bazar Bus Stand', 'name_bn' => 'নতুন বাজার বাস স্ট্যান্ড', 'area_thana' => 'Vatara', 'lat' => 23.7979, 'lng' => 90.4252, 'type' => 'bus_stand'],
    ['name_en' => 'Bashundhara Gate', 'name_bn' => 'বসুন্ধরা আবাসিক গেট', 'area_thana' => 'Vatara', 'lat' => 23.8136, 'lng' => 90.4241, 'type' => 'general'],
    ['name_en' => 'Jamuna Future Park', 'name_bn' => 'যমুনা ফিউচার পার্ক', 'area_thana' => 'Vatara', 'lat' => 23.8135, 'lng' => 90.4215, 'type' => 'general'],
    ['name_en' => 'Kuril Bishwa Road Flyover', 'name_bn' => 'কুড়িল বিশ্বরোড', 'area_thana' => 'Khilkhet', 'lat' => 23.8210, 'lng' => 90.4208, 'type' => 'intersection'],

    // Mohakhali & Cantonment
    ['name_en' => 'Mohakhali Rail Gate', 'name_bn' => 'মহাখালী রেল গেট', 'area_thana' => 'Mohakhali', 'lat' => 23.7776, 'lng' => 90.4043, 'type' => 'bus_stand'],
    ['name_en' => 'Mohakhali Bus Terminal', 'name_bn' => 'মহাখালী বাস টার্মিনাল', 'area_thana' => 'Mohakhali', 'lat' => 23.7761, 'lng' => 90.3995, 'type' => 'bus_stand'],
    ['name_en' => 'Mohakhali Wireless Gate', 'name_bn' => 'মহাখালী ওয়ারলেস গেট', 'area_thana' => 'Mohakhali', 'lat' => 23.7788, 'lng' => 90.4082, 'type' => 'intersection'],
    ['name_en' => 'Jahangir Gate (Cantonment)', 'name_bn' => 'জাহাঙ্গীর গেট', 'area_thana' => 'Cantonment', 'lat' => 23.7699, 'lng' => 90.3921, 'type' => 'intersection'],

    // Rampura, Badda, Malibagh & Khilgaon
    ['name_en' => 'Rampura Bridge', 'name_bn' => 'রামপুরা ব্রিজ', 'area_thana' => 'Rampura', 'lat' => 23.7619, 'lng' => 90.4219, 'type' => 'intersection'],
    ['name_en' => 'Badda Link Road', 'name_bn' => 'বাড্ডা লিংক রোড', 'area_thana' => 'Badda', 'lat' => 23.7781, 'lng' => 90.4239, 'type' => 'intersection'],
    ['name_en' => 'Uttar Badda', 'name_bn' => 'উত্তর বাড্ডা', 'area_thana' => 'Badda', 'lat' => 23.7885, 'lng' => 90.4255, 'type' => 'general'],
    ['name_en' => 'Merul Badda (Brac University)', 'name_bn' => 'মেরুল বাড্ডা ব্র্যাক বিশ্ববিদ্যালয়', 'area_thana' => 'Badda', 'lat' => 23.7712, 'lng' => 90.4230, 'type' => 'university'],
    ['name_en' => 'Aftabnagar Main Gate', 'name_bn' => 'আফতাবনগর মেইন গেট', 'area_thana' => 'Badda', 'lat' => 23.7630, 'lng' => 90.4245, 'type' => 'general'],
    ['name_en' => 'Malibagh Rail Gate', 'name_bn' => 'মালিবাগ রেল গেট', 'area_thana' => 'Malibagh', 'lat' => 23.7541, 'lng' => 90.4182, 'type' => 'intersection'],
    ['name_en' => 'Mouchak Mor', 'name_bn' => 'মৌচাক মোড়', 'area_thana' => 'Malibagh', 'lat' => 23.7485, 'lng' => 90.4132, 'type' => 'intersection'],
    ['name_en' => 'Moghbazar Wireless Mor', 'name_bn' => 'মগবাজার ওয়ারলেস মোড়', 'area_thana' => 'Ramna', 'lat' => 23.7481, 'lng' => 90.4045, 'type' => 'intersection'],
    ['name_en' => 'Khilgaon Taltola Market', 'name_bn' => 'খিলগাঁও তালতলা মার্কেট', 'area_thana' => 'Khilgaon', 'lat' => 23.7511, 'lng' => 90.4235, 'type' => 'general'],
    ['name_en' => 'Khilgaon Flyover Foot', 'name_bn' => 'খিলগাঁও ফ্লাইওভার', 'area_thana' => 'Khilgaon', 'lat' => 23.7435, 'lng' => 90.4231, 'type' => 'intersection'],
    ['name_en' => 'Shantinagar Mor', 'name_bn' => 'শান্তিনগর মোড়', 'area_thana' => 'Paltan', 'lat' => 23.7412, 'lng' => 90.4121, 'type' => 'intersection'],
    ['name_en' => 'Kakrail Church Mor', 'name_bn' => 'কাকরাইল চার্চ মোড়', 'area_thana' => 'Ramna', 'lat' => 23.7389, 'lng' => 90.4091, 'type' => 'intersection'],
    ['name_en' => 'Bailey Road Officers Club', 'name_bn' => 'বেইলি রোড অফিসার্স ক্লাব', 'area_thana' => 'Ramna', 'lat' => 23.7431, 'lng' => 90.4055, 'type' => 'general'],

    // Shahbagh, University & Ramna
    ['name_en' => 'Shahbagh', 'name_bn' => 'শাহবাগ মোড়', 'area_thana' => 'Shahbagh', 'lat' => 23.7381, 'lng' => 90.3958, 'type' => 'intersection'],
    ['name_en' => 'TSC, Dhaka University', 'name_bn' => 'টিএসসি, ঢাকা বিশ্ববিদ্যালয়', 'area_thana' => 'Shahbagh', 'lat' => 23.7323, 'lng' => 90.3938, 'type' => 'university'],
    ['name_en' => 'Curzon Hall', 'name_bn' => 'কার্জন হল', 'area_thana' => 'Shahbagh', 'lat' => 23.7271, 'lng' => 90.4019, 'type' => 'university'],
    ['name_en' => 'Dhaka Medical College Hospital', 'name_bn' => 'ঢাকা মেডিকেল কলেজ হাসপাতাল', 'area_thana' => 'Shahbagh', 'lat' => 23.7259, 'lng' => 90.3975, 'type' => 'hospital'],
    ['name_en' => 'Katabon Mor', 'name_bn' => 'কাটাবন মোড়', 'area_thana' => 'Shahbagh', 'lat' => 23.7408, 'lng' => 90.3892, 'type' => 'intersection'],
    ['name_en' => 'Nilkhet Book Market', 'name_bn' => 'নীলক্ষেত বই মার্কেট', 'area_thana' => 'New Market', 'lat' => 23.7335, 'lng' => 90.3860, 'type' => 'intersection'],
    ['name_en' => 'New Market Main Gate', 'name_bn' => 'নিউ মার্কেট মেইন গেট', 'area_thana' => 'New Market', 'lat' => 23.7340, 'lng' => 90.3831, 'type' => 'general'],
    ['name_en' => 'Azimpur Bus Stand', 'name_bn' => 'আজিমপুর বাস স্ট্যান্ড', 'area_thana' => 'Lalbagh', 'lat' => 23.7289, 'lng' => 90.3855, 'type' => 'bus_stand'],

    // Motijheel, Paltan & Old Dhaka
    ['name_en' => 'Motijheel Shapla Chattar', 'name_bn' => 'মতিঝিল শাপলা চত্বর', 'area_thana' => 'Motijheel', 'lat' => 23.7297, 'lng' => 90.4174, 'type' => 'intersection'],
    ['name_en' => 'Purana Paltan Mor', 'name_bn' => 'পুরানা পল্টন মোড়', 'area_thana' => 'Paltan', 'lat' => 23.7331, 'lng' => 90.4118, 'type' => 'intersection'],
    ['name_en' => 'Baitul Mukarram National Mosque', 'name_bn' => 'বায়তুল মোকাররম জাতীয় মসজিদ', 'area_thana' => 'Paltan', 'lat' => 23.7295, 'lng' => 90.4128, 'type' => 'general'],
    ['name_en' => 'National Press Club', 'name_bn' => 'জাতীয় প্রেস ক্লাব', 'area_thana' => 'Paltan', 'lat' => 23.7312, 'lng' => 90.4075, 'type' => 'general'],
    ['name_en' => 'Lalbagh Fort', 'name_bn' => 'লালবাগ কেল্লা', 'area_thana' => 'Lalbagh', 'lat' => 23.7198, 'lng' => 90.3882, 'type' => 'general'],
    ['name_en' => 'Chawkbazar Shahi Mosque', 'name_bn' => 'চকবাজার', 'area_thana' => 'Chawkbazar', 'lat' => 23.7171, 'lng' => 90.3955, 'type' => 'intersection'],
    ['name_en' => 'Sadarghat Launch Terminal', 'name_bn' => 'সদরঘাট লঞ্চ টার্মিনাল', 'area_thana' => 'Kotwali', 'lat' => 23.7081, 'lng' => 90.4112, 'type' => 'bus_stand'],
    ['name_en' => 'Wari Baldha Garden', 'name_bn' => 'ওয়ারী বলধা গার্ডেন', 'area_thana' => 'Wari', 'lat' => 23.7188, 'lng' => 90.4185, 'type' => 'general'],
    ['name_en' => 'Jatrabari Mor', 'name_bn' => 'যাত্রাবাড়ী মোড়', 'area_thana' => 'Jatrabari', 'lat' => 23.7121, 'lng' => 90.4342, 'type' => 'intersection'],
    ['name_en' => 'Sayedabad Bus Terminal', 'name_bn' => 'সায়েদাবাদ বাস টার্মিনাল', 'area_thana' => 'Jatrabari', 'lat' => 23.7185, 'lng' => 90.4285, 'type' => 'bus_stand'],

    // Uttara & Airport
    ['name_en' => 'Uttara Sector 3 (Rajlakshmi)', 'name_bn' => 'উত্তরা সেক্টর ৩ (রাজলক্ষ্মী)', 'area_thana' => 'Uttara', 'lat' => 23.8711, 'lng' => 90.3965, 'type' => 'bus_stand'],
    ['name_en' => 'Uttara House Building (Sector 6/7)', 'name_bn' => 'উত্তরা হাউস বিল্ডিং', 'area_thana' => 'Uttara', 'lat' => 23.8778, 'lng' => 90.3995, 'type' => 'intersection'],
    ['name_en' => 'Uttara Diabari (Metro Station North)', 'name_bn' => 'উত্তরা দিয়াবাড়ী (উত্তরা উত্তর)', 'area_thana' => 'Uttara West', 'lat' => 23.8821, 'lng' => 90.3752, 'type' => 'metro_station'],
    ['name_en' => 'Hazrat Shahjalal International Airport', 'name_bn' => 'শাহজালাল আন্তর্জাতিক বিমানবন্দর', 'area_thana' => 'Airport', 'lat' => 23.8512, 'lng' => 90.4075, 'type' => 'general'],
    ['name_en' => 'Khilkhet Bus Stand', 'name_bn' => 'খিলক্ষেত বাস স্ট্যান্ড', 'area_thana' => 'Khilkhet', 'lat' => 23.8315, 'lng' => 90.4172, 'type' => 'bus_stand'],
    ['name_en' => 'Nikunja 2 Gate', 'name_bn' => 'নিকুঞ্জ ২ গেট', 'area_thana' => 'Khilkhet', 'lat' => 23.8340, 'lng' => 90.4185, 'type' => 'general']
];

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO popular_nodes (name_en, name_bn, area_thana, latitude, longitude, node_type, barikoi_place_id)
        SELECT :name_en, :name_bn, :area, :lat, :lng, :type, :bk_id
        WHERE NOT EXISTS (
            SELECT 1 FROM popular_nodes WHERE name_en = :check_en
        )
    ");

    $inserted = 0;
    foreach ($nodes as $n) {
        $bkId = 'BK-DH-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $n['name_en']), 0, 8)) . '-' . rand(10, 99);
        $stmt->execute([
            ':name_en' => $n['name_en'],
            ':name_bn' => $n['name_bn'],
            ':area' => $n['area_thana'],
            ':lat' => $n['lat'],
            ':lng' => $n['lng'],
            ':type' => $n['type'],
            ':bk_id' => $bkId,
            ':check_en' => $n['name_en']
        ]);
        if ($stmt->rowCount() > 0) {
            $inserted++;
        }
    }

    $total = $pdo->query("SELECT COUNT(*) FROM popular_nodes")->fetchColumn();
    echo "[+] Done! Added {$inserted} new landmarks. Total Dhaka nodes in DB: {$total}\n";

} catch (Throwable $e) {
    echo "[!] Error seeding nodes: " . $e->getMessage() . "\n";
    exit(1);
}
