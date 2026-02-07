           if( $new['appoint_date']=== null || $new['appoint_time']=== null ) {

                    // Query to get all future appointments for the doctor, sorted by start time
                    $stmt = $dbh->prepare("
                        SELECT gen_appoint_date_time AS appointment_start, gen_appoint_end AS appointment_end
                        FROM r_med_appointment
                        WHERE element != {$_POST['e']} AND attending_physician = :doctor_id AND gen_appoint_date_time >= NOW()
                        ORDER BY gen_appoint_date_time ASC
                    ");
                    $stmt->execute(['doctor_id' => $doctor_id]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Convert to DateTime objects
                    $appointments = [];
                    foreach ($rows as $row) {
                        $appointments[] = [
                            'start' => new DateTime($row['appointment_start']),
                            'end' => new DateTime($row['appointment_end'])
                        ];
                    }

                    $now = new DateTime();
                    $appt_index = 0;
                    $max_days = 365; // Safety limit to prevent infinite loop (1 year ahead)

                    for ($day_offset = 0; $day_offset < $max_days; $day_offset++) {
                        $day = (clone $now)->modify("+$day_offset days");

                        // Skip Fridays
                        if ($day->format('N') == 5) {
                            continue;
                        }

                        $work_start = (clone $day)->setTime(8, 0, 0);
                        $work_end = (clone $day)->setTime(17, 0, 0);

                        $current = ($now > $work_start) ? clone $now : clone $work_start;

                        if ($current >= $work_end) {
                            continue; // No available time today, skip to next day
                        }

                        while (true) {
                            // Skip past appointments
                            while ($appt_index < count($appointments) && $appointments[$appt_index]['end'] <= $current) {
                                $appt_index++;
                            }

                            // Find the next appointment that starts after current and within the day
                            $next_appt = null;
                            if ($appt_index < count($appointments)) {
                                $appt = $appointments[$appt_index];
                                if ($appt['start'] < $work_end) {
                                    if ($appt['start'] > $current) {
                                        $next_appt = $appt;
                                    } else {
                                        // Overlapping or starting before/at current, move current to end
                                        $current = max($current, $appointments[$appt_index]['end']);
                                        $appt_index++;
                                        if ($current >= $work_end) {
                                            break;
                                        }
                                        continue;
                                    }
                                }
                            }

                            // Calculate gap end
                            $gap_end = clone $work_end;
                            if ($next_appt) {
                                $gap_end = min($gap_end, $next_appt['start']);
                            }

                            // Calculate gap duration in minutes
                            $diff = $gap_end->getTimestamp() - $current->getTimestamp();
                            $gap_minutes = $diff / 60;

                            if ($gap_minutes >= $duration_minutes) {
                                // Check if the proposed end is within work_end (should be, but confirm)
                                $proposed_end = (clone $current)->modify("+$duration_minutes minutes");
                                if ($proposed_end <= $work_end) {
                                    // Found a slot

                                    //$dt = $current->format('Y-m-d H:i:s');
                                    $availableStartDate = $current->format('Y-m-d');
                                    $availableStartTime = $current->format('H:i:s');

                                    $new['appoint_date']= $availableStartDate;
                                    $new['appoint_time']= $availableStartTime;
                                    goto ended;
                                }
                            }

                            // Not enough time in this gap
                            if ($next_appt) {
                                $current = max($current, $next_appt['end']);
                                $appt_index++;
                            } else {
                                // No more appointments today, and gap too small, end day
                                break;
                            }

                            if ($current >= $work_end) {
                                break;
                            }
                        }
                    }

                    //return "No available slot found within the next $max_days days.";

                    ended:
