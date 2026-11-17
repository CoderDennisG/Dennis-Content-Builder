# Skill: Workshop intake (building a workshop from the client's document)

The client sends one document per workshop (a Word file; its text is pasted or uploaded into the chat). Denise turns it into a workshop DRAFT the same way Dennis does by hand: create the workshop, fill its ACF fields, apply the "WS Guest Faculty Template" page template, replace the template's narrative text section by section, and report what could not be placed. Denise never publishes; the person reviews the draft in Elementor and publishes.

## Non-negotiable rules

1. **Photos are never Denise's job.** Do not add, pick or search images. List the image slots the document mentions ("fold image", "photo of Chip", "slider of the campus") in the final report so the person adds them.
2. **Report, don't invent.** Anything in the document with no matching field or template section is NOT built: it goes in the report under "Not placed". Any template section the document does not cover is reported under "Template sections without content" and left as is unless the person says to remove it. Documents often carry extra material; that is expected.
3. **Faculty by lookup only.** Resolve every person named as host, facilitator or faculty with `find_faculty`. A name that is not found is reported, never created and never guessed.
4. **Ask before replacing rows.** On an EXISTING workshop, writing a repeater (FAQ, testimonials) replaces all rows: show the new rows and ask first. On a brand-new draft there is nothing to replace.
5. **Missing data is a question, not a default.** Fields the document does not give (see the list below) are asked for in the summary step; do not fill them with guesses.
6. **Two-step flow.** Step 1: read the document and reply with a summary (what goes where, what is missing, what is not placed, which people were matched to which ids) and wait for "go". Step 2: build, then report.

## Step 2, in order

1. `find_elementor_template` for "WS Guest Faculty Template" (confirm the id each time; do not hard-code it).
2. `create_elementor_draft_from_template` with post_type `workshop`, the title, and that template id. The result lists the page's root sections with their ids and names.
3. `update_fields` on the new draft with everything from the ACF mapping (one call).
4. `read_elementor_page` / `read_elementor_element` on the draft, then `update_elementor_element` per section to replace narrative text (one element at a time; keep the template's structure and styles; change text only). The narrative widgets are legacy types: `text-editor` (key `editor`, HTML paragraphs), `icon-list` (key `icon_list`, an array of rows `{"text": "…"}`; icons are kept automatically), `shortcode` (key `shortcode`), `heading` (key `title`); V4 `e-heading` / `e-paragraph` take `title` / `paragraph`. Legacy widgets can only be changed while the post is a draft.
5. `remove_elementor_element` for the other campus's "Location - …" and "Dining - …" sections, and for the second host card when there is one host. Nothing else is removed without being asked.
6. Report (format below) with the Elementor edit link.

## ACF mapping (post type `workshop`)

| In the document | Field | Notes |
|---|---|---|
| Title line | post title and `workshop_title` | |
| Subtitle / one-line pitch | `short_description` | |
| Venue (Rising Circle Ranch, Santa Fe / Baja campus) | `campus` | `santa-fe`, `baja-mexico`, `other` |
| Dates ("December 13–18, 2027") | `start_date`, `end_date` (YYYY-MM-DD), `number_of_days`, `duration_label` ("5 days") | count the nights: 13–18 = 5 |
| "Meet Your Host" person | `featured_faculty` (ids) | via `find_faculty` |
| "Supported by" facilitators | `faculty` (ids) | via `find_faculty`; rendered by the template's "Supported by" shortcode |
| "What People Are Saying" quotes | `testimonial_entries` rows: `name`, `position`, `subject`, `testimonial` | leave `photo` empty; attribution like "3rd Annual Film Fest Participant" goes in `name` |
| "Frequently Asked Questions" | `faq_entries` rows: `question`, `answer` | answer may contain simple HTML paragraphs |

Ask for these when the document does not state them: `pillar`, `workshop_type` (core / guest / mea-programs / custom), `guest_workshop_type`, `sku`, `shared_room_price`, `private_room_price`, `spots_left`, early-bird settings, and the schedule (see Agenda).

## Template mapping ("WS Guest Faculty Template", a page template; sections are named in the editor)

The template is a copy of a real workshop page (a retirement workshop by Bob and Amie Laura), not blanks: every narrative element still holds that page's text and MUST be replaced or the old content ships. Sections, by their editor name:

| Document part | Template section | What to change |
|---|---|---|
| Opening paragraphs under the first headline (e.g. "Great films and conversations that stay with you" + 2 paragraphs) | **S1** | the text-editor widgets |
| "The Location" paragraph | **Location - Santa Fe** or **Location - Baja** | the paragraph text-editor; remove the other campus's section |
| "The Experience" | **Experience** (the first one, with the "The Experience" heading) | the paragraph text-editor |
| "What You'll Leave With" bullets | **What You Will Leave With** | the icon-list items (`icon_list` entries' `text`); keep icons |
| "Who This Is for" / "Who This Isn't for" bullets | **Who is this for** | the two icon-lists |
| "Meet Your Host": title line, name, bio | **Meet Your Host** | first card's e-paragraph (title line), e-heading (name), text-editor (bio); remove the second card when there is one host |
| "More Kind Words" quotes with names | **More kind words** | the three quote text-editors and three name headings |
| "Eat, Rest, Explore" | **Dining - Santa Fe** or **Dining - Baja** | the paragraph; remove the other campus's section |
| "What's Included" bullets | **What's Included** | the icon-list |
| Agenda | **Schedule** | the shortcode widget's text (see Agenda) |

Left alone (they render from ACF or are fixed): **FOLD**, **Table Of Contents**, **Notice**, **Podcast**, **Live Sessions**, **Testimonials**, **Booking**, **FAQ** (its intro paragraph is about retirement: report it), **Advisors**, the "Still Have Questions" chat block.

Known gaps to report rather than solve: the second **Experience** section ("What Makes This Experience Unique", an icon-list) has no counterpart in most documents; a document section such as "Come for the films. Stay for the conversations." has no template section. Ask whether to reuse that section, remove it, or leave both.

## Agenda ("schedule converter")

The Schedule section holds a classic shortcode widget with an enclosing block:

```
[mea_workshops action="workshop_agenda"]
#1
- 4:00 PM: Arrival & Check-In
- 5:30 PM: Welcome Reception & Dinner
#2
- 7:30 AM: Mind + Body
[/mea_workshops]
```

Rules: one `#N` marker per day in order; one `- TIME: label` line per entry with a colon (not a dash); NO weekday or date lines, because the accordion computes each day's date from the workshop's `start_date`. Use `convert_agenda` with the document's agenda text and the start date; it returns the block and warnings, including a mismatch between the agenda's day-1 date and `start_date`, which must be reported. Write the block into the Schedule section's shortcode widget (`shortcode` setting). A document without an agenda: ask for it; never leave the template's old agenda in place, and never invent one. The input is plain text, not Elementor JSON.

## The report (step 2 ends with this)

- Draft title and Elementor edit link.
- Fields written, and people matched (name → id).
- Template sections replaced; sections removed.
- **Not placed**: document parts with nowhere to go.
- **Template sections without content**: sections the document did not cover.
- **Needs you**: images to add, fields to fill in, people not found, agenda missing or mismatched.
